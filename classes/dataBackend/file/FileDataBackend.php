<?php

namespace malkusch\bav;

/**
 * It uses the huge file from the Bundesbank and uses a binary search to find a row.
 * This is the easiest way to use BAV. BAV can work as a standalone application without
 * any DBS.
 *
 * @author Markus Malkusch <markus@malkusch.de>
 * @license GPL
 */
class FileDataBackend extends DataBackend
{
    
    // @codingStandardsIgnoreStart
    const DOWNLOAD_URI = "http://www.bundesbank.de/Redaktion/DE/Standardartikel/Aufgaben/Unbarer_Zahlungsverkehr/bankleitzahlen_download.html";
    // @codingStandardsIgnoreEnd

    /**
     * @var array
     */
    private $contextCache = array();

    /**
     * @var FileParser
     */
    private $parser;

    /**
     * @param String $file The data source
     */
    public function __construct($file = null)
    {
        $this->parser = new FileParser($file);
    }

    /**
     * Returns the path to the data file.
     *
     * @return string
     */
    public function getFile()
    {
        return $this->parser->getFile();
    }

    /**
     * For the file of March 8th 2010 (blz_20100308.txt)
     * Bundesbank appended new banks at the end of the file.
     * That broked binary search. This method sorts the lines so
     * that binary search is working again.
     *
     * Be aware that this needs some amount of memory.
     *
     * @param String $file
     * @throws DataBackendIOException
     */
    private function sortFile($file)
    {
        //read the unordered bank file
        $lines = file($file);
        if (! is_array($lines) || empty($lines)) {
            throw new DataBackendIOException("Could not read lines in '$file'.");

        }

        //build a sorted index for the bankIDs
        $index = array();
        foreach ($lines as $line => $data) {
            $bankID = substr($data, FileParser::BANKID_OFFSET, FileParser::BANKID_LENGTH);
            $index[$line] = $bankID;

        }
        asort($index);

        //write a sorted bank file atomically
        $temp    = tempnam(self::getTempdir(), "");
        $tempH   = fopen($temp, 'w');
        if (! ($temp && $tempH)) {
            throw new DataBackendIOException("Could not open a temporary file.");

        }
        foreach (array_keys($index) as $line) {
            $data = $lines[$line];

            $writtenBytes = fputs($tempH, $data);
            if ($writtenBytes != strlen($data)) {
                throw new DataBackendIOException("Could not write sorted data: '$data' into $temp.");

            }

        }
        fclose($tempH);
        $this->safeRename($temp, $file);
    }

    /**
     * @see DataBackend::uninstall()
     * @throws DataBackendIOException
     */
    public function uninstall()
    {
        if (! unlink($this->parser->getFile())) {
            throw new DataBackendIOException();

        }
    }

    /**
     * @see DataBackend::install()
     * @throws DataBackendIOException
     */
    public function install()
    {
        $this->update();
    }

    /**
     * This method works only if your PHP is compiled with cURL.
     * TODO: test this with a proxy
     *
     * @see DataBackend::update()
     * @throws DataBackendIOException
     */
    public function update()
    {
        $ch = curl_init(self::DOWNLOAD_URI);
        if (! is_resource($ch)) {
            throw new DataBackendIOException();

        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        if ($curl_info['http_code'] >= 400) {
            throw new DataBackendIOException(
                sprintf(
                    "Failed to download '%s'. HTTP Code: %d",
                    self::DOWNLOAD_URI,
                    $curl_info['http_code']
                )
            );
        }
        if (! $content) {
            throw new DataBackendIOException(
                "Failed to download '" . self::DOWNLOAD_URI . "'."
            );

        }
        $isTXT = preg_match('/Bankleitzahlendateien ungepackt.+href *= *"([^"]+\.txt[^"]*)"/sU', $content, $txtMatches);
        $isZIP = (exec('unzip -v') == '')
               ? false
               : preg_match('/Bankleitzahlendateien gepackt.+href *= *"([^"]+\.zip[^"]*)"/sU', $content, $zipMatches);

        /**
         * There is an unresolved bug, that doesn't allow to uncompress
         * the zip archive. Zip support is disabled until it's repaired.
         *
         * @see http://sourceforge.net/forum/message.php?msg_id=7555232
         * TODO enable Zip support
         */
        $isZIP = false;

        if (! ($isTXT || $isZIP)) {
            throw new DataBackendException("Could not find a file.");

        }

        $temp    = tempnam(self::getTempdir(), "");
        $tempH   = fopen($temp, 'w');
        if (! ($temp && $tempH)) {
            throw new DataBackendIOException();

        }
        $path = $isZIP ? $zipMatches[1] : $txtMatches[1];
        if (strlen($path) > 0 && $path{0} != "/") {
            $path = sprintf("/%s/%s", dirname(self::DOWNLOAD_URI), $path);

        }
        $pathParts = explode('/', $path);
        foreach ($pathParts as $i => $part) {
            switch ($part) {
                case '..':
                    unset($pathParts[$i-1]);
                    // fall-through as the current part ("..") should be removed as well.

                case '.':
                    unset($pathParts[$i]);
                    break;
            }

        }
        $path = implode('/', $pathParts);
        $urlParts = parse_url(self::DOWNLOAD_URI);
        $url = sprintf("%s://%s%s", $urlParts["scheme"], $urlParts["host"], $path);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $tempH);
        if (! curl_exec($ch)) {
            fclose($tempH);
            unlink($temp);
            throw new DataBackendIOException(curl_error($ch), curl_errno($ch));

        }
        fclose($tempH);
        curl_close($ch);

        if ($isZIP) {
            $file = tempnam(self::getTempdir(), "");
            if (! $file) {
                unlink($temp);
                throw new DataBackendIOException();

            }
            system('unzip -qqp '.$temp.' > '.$file, $error);
            if (! unlink($temp) || $error !== 0) {
                unlink($file);
                throw new DataBackendIOException();

            }

        } else {
            $file = $temp;

        }

        // blz_20100308.txt is not sorted.
        $parser     = new FileParser($file);
        $lastBankID = $parser->getBankID($parser->getLines());
        if ($lastBankID < 80000000) {
            $this->sortFile($file);

        }

        $this->safeRename($file, $this->parser->getFile());
        chmod($this->parser->getFile(), 0644);
    }

    /**
     * Renames a file atomically between different filesystems.
     *
     * @param String $source path of the source
     * @param String $destination path of the destination
     * @throws DataBackendIOException
     */
    private function safeRename($source, $destination)
    {
        $isRenamed = @rename($source, $destination);
        if ($isRenamed) {
            return;

        }

        // copy to the target filesystem
        $tempFileOnSameFS = "$destination.tmp";

        $isCopied = copy($source, $tempFileOnSameFS);
        if (! $isCopied) {
            throw new DataBackendIOException(
                "failed to copy $source to $tempFileOnSameFS."
            );

        }

        $isUnlinked = unlink($source);
        if (! $isUnlinked) {
            trigger_error("Failed to unlink $source.");

        }

        $isRenamed = rename($tempFileOnSameFS, $destination);
        if (! $isRenamed) {
            throw new DataBackendIOException(
                "failed to rename $tempFileOnSameFS to $destination."
            );

        }
    }

    /**
     * @throws DataBackendIOException
     * @throws DataBackendException
     * @return Bank[]
     * @see DataBackend::getAllBanks()
     */
    public function getAllBanks()
    {
        try {
            for ($i = 0; $i < $this->parser->getLines(); $i++) {
                if (isset($this->instances[$this->parser->getBankID($i)])) {
                    continue;

                }
                $line = $this->parser->readLine($i);
                $bank = $this->parser->getBank($this, $line);
                $this->instances[$bank->getBankID()] = $bank;
                $this->contextCache[$bank->getBankID()] = new FileParserContext($i);
            }
            return array_values($this->instances);

        } catch (FileParserIOException $e) {
            throw new DataBackendIOException();

        } catch (FileParserException $e) {
            throw new DataBackendException();

        }
    }

    /**
     * @throws DataBackendIOException
     * @throws BankNotFoundException
     * @param String $bankID
     * @see DataBackend::getNewBank()
     * @return Bank
     */
    public function getNewBank($bankID)
    {
        try {
            $this->parser->rewind();
            /**
             * TODO Binary Search is also possible on $this->contextCache,
             *      to reduce the interval of $offset and $end;
             */
            if (isset($this->contextCache[$bankID])) {
                return $this->findBank(
                    $bankID,
                    $this->contextCache[$bankID]->getLine(),
                    $this->contextCache[$bankID]->getLine()
                );

            } else {
                return $this->findBank($bankID, 0, $this->parser->getLines());

            }

        } catch (FileParserException $e) {
            throw new DataBackendIOException();

        }
    }

    /**
     * @throws BankNotFoundException
     * @throws ParseException
     * @throws FileParserIOException
     * @param int $bankID
     * @param int $offset the line number to start
     * @param int $length the line count
     * @return Bank
     */
    private function findBank($bankID, $offset, $end)
    {
        if ($end - $offset < 0) {
            throw new BankNotFoundException($bankID);

        }
        $line = $offset + (int)(($end - $offset) / 2);
        $blz  = $this->parser->getBankID($line);

        /**
         * This handling is bad, as it may double the work
         */
        if ($blz == '00000000') {
            try {
                return $this->findBank($bankID, $offset, $line - 1);

            } catch (BankNotFoundException $e) {
                return $this->findBank($bankID, $line + 1, $end);

            }

        } elseif (! isset($this->contextCache[$blz])) {
            $this->contextCache[$blz] = new FileParserContext($line);

        }

        if ($blz < $bankID) {
            return $this->findBank($bankID, $line + 1, $end);

        } elseif ($blz > $bankID) {
            return $this->findBank($bankID, $offset, $line - 1);

        } else {
            return $this->parser->getBank($this, $this->parser->readLine($line));

        }
    }

    /**
     * @see DataBackend::getMainAgency()
     * @throws DataBackendException
     * @throws NoMainAgencyException
     * @return Agency
     */
    public function getMainAgency(Bank $bank)
    {
        try {
            $context = $this->defineContextInterval($bank->getBankID());
            for ($line = $context->getStart(); $line <= $context->getEnd(); $line++) {
                $content = $this->parser->readLine($line);
                if ($this->parser->isMainAgency($content)) {
                    return $this->parser->getAgency($bank, $content);

                }
            }
            // Maybe there are banks without a main agency
            throw new NoMainAgencyException($bank);

        } catch (UndefinedFileParserContextException $e) {
            throw new LogicException("Start and end should be defined.");

        } catch (FileParserIOException $e) {
            throw new DataBackendIOException("Parser Exception at bank {$bank->getBankID()}");

        } catch (ParseException $e) {
            throw new DataBackendException(get_class($e) . ": " . $e->getMessage());

        }
    }

    /**
     * @see DataBackend::getAgenciesForBank()
     * @throws DataBackendIOException
     * @throws DataBackendException
     * @return Agency[]
     */
    public function getAgenciesForBank(Bank $bank)
    {
        try {
            $context = $this->defineContextInterval($bank->getBankID());
            $agencies = array();
            for ($line = $context->getStart(); $line <= $context->getEnd(); $line++) {
                $content = $this->parser->readLine($line);
                if (! $this->parser->isMainAgency($content)) {
                    $agencies[] = $this->parser->getAgency($bank, $content);

                }
            }
            return $agencies;

        } catch (UndefinedFileParserContextException $e) {
            throw new LogicException("Start and end should be defined.");

        } catch (FileParserIOException $e) {
            throw new DataBackendIOException();

        } catch (ParseException $e) {
            throw new DataBackendException();

        }
    }

    /**
     * @return FileParserContext
     */
    private function defineContextInterval($bankID)
    {
        if (! isset($this->contextCache[$bankID])) {
            throw new LogicException("The contextCache object should exist!");

        }
        $context = $this->contextCache[$bankID];
        /**
         * Find start
         */
        if (! $context->isStartDefined()) {
            for ($start = $context->getLine() - 1; $start >= 0; $start--) {
                if ($this->parser->getBankID($start) != $bankID) {
                    break;

                }
            }
            $context->setStart($start + 1);

        }
        /**
         * Find end
         */
        if (! $context->isEndDefined()) {
            for ($end = $context->getLine() + 1; $end <= $this->parser->getLines(); $end++) {
                if ($this->parser->getBankID($end) != $bankID) {
                    break;

                }
            }
            $context->setEnd($end - 1);

        }
        return $context;
    }

    /**
     * @throws DataBackendIOException
     * @return String a writable directory for temporary files
     */
    public static function getTempdir()
    {
        $tmpDirs = array(
            function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : false,
            empty($_ENV['TMP'])    ? false : $_ENV['TMP'],
            empty($_ENV['TMPDIR']) ? false : $_ENV['TMPDIR'],
            empty($_ENV['TEMP'])   ? false : $_ENV['TEMP'],
            ini_get('upload_tmp_dir'),
            '/tmp'
        );

        foreach ($tmpDirs as $tmpDir) {
            if ($tmpDir && is_writable($tmpDir)) {
                return realpath($tmpDir);

            }

        }

        $tempfile = tempnam(uniqid(mt_rand(), true), '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
            return realpath(dirname($tempfile));

        }

        throw new DataBackendIOException();
    }

    /**
     * Returns the timestamp of the last update.
     *
     * @return int timestamp
     * @throws DataBackendException
     */
    public function getLastUpdate()
    {
        $time = filemtime($this->parser->getFile());
        if ($time === false) {
            return new DataBackendException(
                "Could not read modification time from {$this->parser->getFile()}"
            );

        }
        return $time;
    }

    /**
     * Returns true if the backend was installed.
     *
     * @return bool
     */
    public function isInstalled()
    {
        return file_exists($this->parser->getFile())
            && filesize($this->parser->getFile()) > 0;
    }
}
