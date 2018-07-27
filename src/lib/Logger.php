<?php

namespace cruxinator\TorClient\Lib;


class Logger
{

    public static function getLogger($name){
        return new Logger();
    }

    public function info($message){
        echo $message . "\r\n";
    }

    public function warning($message){
        echo $message . "\r\n";

    }

    public function severe($message){
        echo $message . "\r\n";

    }
    public function addHandler($fileHandle){
        echo $message . "\r\n";

    }

}
