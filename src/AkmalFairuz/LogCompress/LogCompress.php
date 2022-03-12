<?php

declare(strict_types=1);

namespace AkmalFairuz\LogCompress;

use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\MainLoggerThread;
use const PTHREADS_INHERIT_NONE;

class LogCompress extends PluginBase{

    public function onEnable(): void{
        /** @var MainLogger $logger */
        $logger = Server::getInstance()->getLogger();
        /** @var MainLoggerThread $loggerThread */
        $loggerThread = self::forceGetProps($logger, "logWriterThread");

        $loggerCompressThread = new LogCompressThread(Server::getInstance()->getDataPath() . "server.log");
        $loggerCompressThread->start(PTHREADS_INHERIT_NONE);

        self::forceSetProps($logger, "logWriterThread", $loggerCompressThread);

        $loggerThread->shutdown();
    }

    public function onDisable(): void{
    }

    public static function forceSetProps($object, string $propName, $value) {
        try{
            $reflection = new \ReflectionClass($object);
            $prop = $reflection->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($object, $value);
        } catch(\ReflectionException $e) {
        }
    }

    public static function forceGetProps($object, string $propName) {
        try{
            $reflection = new \ReflectionClass($object);
            $prop = $reflection->getProperty($propName);
            $prop->setAccessible(true);
            return $prop->getValue($object);
        } catch(\ReflectionException $e) {
            return null;
        }
    }
}