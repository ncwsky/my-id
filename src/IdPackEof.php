<?php
namespace MyId;

use Workerman\Connection\TcpConnection;

/**
 * LogPackN2 Protocol.
 */
class IdPackEof
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        if(substr($buffer, 0, 3)==='GET'){
            $pos = \strpos($buffer, "\r\n\r\n");
            if ($pos === false) {
                if ($recv_len = \strlen($buffer) >= 16384) { //url接受最大的长度为16384个字符
                    $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");
                    return 0;
                }
                return 0;
            }
            return $pos + 4;
        }
        $pos = \strpos($buffer, "\n");
        if ($pos === false) {
            return 0;
        }
        return $pos + 1;
    }


    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        if (!is_scalar($buffer)) $buffer = IdLib::toJson($buffer);
        return $buffer . "\r\n";
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return rtrim($buffer, "\r\n");
        return substr($buffer, 0, -2);
    }
}