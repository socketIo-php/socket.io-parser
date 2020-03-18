<?php

namespace SocketIoParser;

use Emitter\Emitter;
use SocketIoParser\Enums\PacketEnum;

/**
 * Class Parser
 *
 * @method on($event, $fn)
 *
 * @package SocketIoParser
 */
class Parser
{
    /**
     * Current protocol version.
     */
    public const PROTOCOL = 4;

    public static function getPacketList()
    {
        static $buffer;

        if ($buffer === null) {
            $buffer = PacketEnum::getAllText();
        }

        return $buffer;
    }

    public static function getErrorPacket()
    {
        $data = PacketEnum::ERROR . '"encode error"';

        return $data;
    }


    private static function encodeAsString(array $obj)
    {
        // first is type
        $str = '' . $obj['type'];

        // attachments if we have them
        if (PacketEnum::BINARY_EVENT === $obj['type'] || PacketEnum::BINARY_ACK === $obj['type']) {
            $str .= $obj[' attachments'] . '-';
        }
        
        // if we have a namespace other than `/`
        // we append it followed by a comma `,`
        if ($obj['nsp'] && '/' !== $obj['nsp']) {
            $str .= $obj['nsp'] . ',';
        }

        // immediately followed by the id
        if (isset($obj['id']) && !empty($obj['id'])) {
            $str .= $obj['id'];
        }

        // json data
        if (isset($obj['data']) && null !== $obj['data']) {
            $payload = self::tryStringify($obj['data']);
            if ($payload !== false) {
                $str .= $payload;
            } else {
                return self::getErrorPacket();
            }
        }

        return $str;
    }

    private static function tryStringify($data)
    {
        try {
            if (is_object($data)) {
                throw new \Exception('Un parse object');
            }

            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param array    $obj
     * @param callable $callback
     *
     * @throws \Exception
     */
    public static function encode(array $obj, callable $callback)
    {
        if (PacketEnum::BINARY_ACK === $obj['type']) {
            throw new \Exception('not support binary');
        } else {
            $encoding = self::encodeAsString($obj);
            $callback([$encoding]);
        }
    }

    public static function add($protocol)
    {
        $packet = '';
        if (is_string($protocol)) {
            $packet = self::decodeString($protocol);
            self::getEmitter()->emit('decoded', $packet);
        }
    }

    /**
     * @param $str
     */
    private static function decodeString(string $str)
    {
        $i = 0;
        // look up type
        $p = [
            'type' => intval($str{$i})
        ];

        if (!in_array($p['type'], array_keys(self::getPacketList()))) {
            return self::error('unknown packet type ' . $p['type']);
        }

        // look up attachments if type binary
        if (PacketEnum::BINARY_EVENT === $p['type'] || PacketEnum::BINARY_EVENT === $p['type']) {
            $buf = '';
            while ($str{++$i} !== '-') {
                $buf .= $str{$i};
                if ($i == strlen($str)) break;
            }
            if ($buf != self::Number($buf) || $str{$i} !== '-') {
                throw new \Exception('Illegal attachments');
            }
            $p['attachments'] = self::Number($buf);
        }

        // look up namespace (if any)
        if ('/' === $str{$i + 1}) {
            $p['nsp'] = '';
            while (++$i) {
                $c = $str{$i};
                if (',' === $c) break;
                $p['nsp'] .= $c;
                if ($i === strlen($str)) break;
            }
        } else {
            $p['nsp'] = '/';
        }

        // look up id
        $next = $str{$i + 1};
        if ('' !== $next && self::Number($next) == $next) {
            $p['id'] = '';
            while (++$i) {
                $c = $str{$i};
                if (null == $c || self::Number($c) != $c) {
                    --$i;
                    break;
                }
                $p['id'] .= $str{$i};
                if ($i === strlen($str)) break;
            }
            $p['id'] = intval($p['id']);
        }

        // look up json data
        if ($str{++$i}) {
            $payload = self::tryParse(substr($str, $i));
            $isPayloadValid = $payload !== false && ($p['type'] === PacketEnum::ERROR || is_array($payload));
            if ($isPayloadValid) {
                $p['data'] = $payload;
            } else {
                return self::error('invalid payload');
            }
        }

        return $p;
    }


    private static function tryParse($str)
    {
        $data = json_decode($str, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        } else {
            return $data;
        }
    }

    private static function error($msg)
    {
        return [
            'type' => PacketEnum::ERROR,
            'data' => 'parser error: ' . $msg
        ];
    }

    private static function Number($value)
    {
        if (preg_match('/^[0-9]+$/', $value)) {
            return (int)$value;
        }

        if ($value === '') {
            return 0;
        }

        return null;
    }

    public static function getEmitter(bool $new = false)
    {
        static $emitter;

        if ($emitter === null || $new) {
            $emitter = new Emitter();
        }

        return $emitter;
    }

    public static function __callStatic($name, $arguments)
    {
        if (method_exists(self::getEmitter(), $name)) {
            return self::getEmitter()->{$name}(...$arguments);
        } else {
            throw new \RuntimeException('not exits method: ' . $name);
        }
    }
}