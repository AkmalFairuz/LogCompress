<?php

declare(strict_types=1);

namespace AkmalFairuz\LogCompress;

use pocketmine\Server;
use pocketmine\utils\AssumptionFailedError;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function touch;

final class LogCompressThread extends \Thread{

    private const MAX_FILE_SIZE = 32 * 1024 * 1024; //32 MB

    private string $logFile;
    private \Threaded $buffer;
    private bool $syncFlush = false;
    private bool $shutdown = false;
    private string $compressedLogsDir;

    public function __construct(string $logFile){
        $this->buffer = new \Threaded();
        touch($logFile);
        $this->logFile = $logFile;

        $compressedLogsDir = Server::getInstance()->getDataPath() . "compressed_logs";

        if(!@mkdir($compressedLogsDir) && !is_dir($compressedLogsDir)){
            throw new \RuntimeException("Unable to create archive directory: " . (
                is_file($compressedLogsDir) ? "it already exists and is not a directory" : "permission denied"));
        }
        $this->compressedLogsDir = $compressedLogsDir;
    }

    public function write(string $line) : void{
        $this->synchronized(function() use ($line) : void{
            $this->buffer[] = $line;
            $this->notify();
        });
    }

    public function syncFlushBuffer() : void{
        $this->syncFlush = true;
        $this->synchronized(function() : void{
            $this->notify(); //write immediately

            while($this->syncFlush){
                $this->wait(); //block until it's all been written to disk
            }
        });
    }

    public function shutdown() : void{
        $this->synchronized(function() : void{
            $this->shutdown = true;
            $this->notify();
        });
        $this->join();
    }

    /**
     * @param resource $logResource
     */
    private function writeLogStream($logResource, int &$offset) : bool{
        while($this->buffer->count() > 0){
            /** @var string $chunk */
            $chunk = $this->buffer->shift();
            fwrite($logResource, $chunk);
            $offset += strlen($chunk);
            if($offset >= self::MAX_FILE_SIZE){
                return false;
            }
        }

        $this->synchronized(function() : void{
            if($this->syncFlush){
                $this->syncFlush = false;
                $this->notify(); //if this was due to a sync flush, tell the caller to stop waiting
            }
        });
        return true;
    }

    /** @return resource */
    private function openLogFile(string $file, int &$size){
        $logResource = fopen($file, "ab");
        if(!is_resource($logResource)){
            throw new \RuntimeException("Couldn't open log file");
        }
        $stat = fstat($logResource);
        if($stat === false) throw new AssumptionFailedError("ftell() should not fail here");
        $size = $stat['size'];
        return $logResource;
    }

    private function compressLogFile() : void{
        $i = 0;
        $date = date("Y-m-d\TH.i.s");
        do{
            //this shouldn't be necessary, but in case the user messes with the system time for some reason ...
            $out = $this->compressedLogsDir . "/server.${date}_$i.log.gz";
            $i++;
        }while(file_exists($out));

        $logFile = fopen($this->logFile, 'rb');
        $archiveFile = gzopen($out, 'wb');
        if($logFile === false || $archiveFile === false){
            throw new AssumptionFailedError();
        }

        if(stream_copy_to_stream($logFile, $archiveFile) === false){
            throw new AssumptionFailedError("Something is wrong");
        }
        fclose($logFile);
        fclose($archiveFile);
        @unlink($this->logFile);
    }

    public function run() : void{
        $size = 0;
        $logResource = $this->openLogFile($this->logFile, $size);

        while(!$this->shutdown){
            while(!$this->writeLogStream($logResource, $size)){
                fclose($logResource);
                $this->compressLogFile();
                $logResource = $this->openLogFile($this->logFile, $size);
            }
            $this->synchronized(function() : void{
                if(!$this->shutdown){
                    $this->wait();
                }
            });
        }

        $this->writeLogStream($logResource, $size);

        fclose($logResource);
    }
}