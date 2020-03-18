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

        /*
             // attachments if we have them
             if (exports.BINARY_EVENT === obj.type || exports.BINARY_ACK === obj.type) {
             str += obj.attachments + '-';
         }
        */
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
        if (PacketEnum::BINARY_EVENT === $p['type'] ||PacketEnum::BINARY_EVENT === $p['type']) {
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
        if ('' !== $next && (preg_match('/^\d+$/', $next) && intval($next) == $next)) {
            $p['id'] = '';
            while (++$i) {
                $c = $str{$i};
                if (null == $c || !preg_match('/^\d+$/', $c)) {
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


    /**
     * Encodes a packet.
     *
     *     <packet type id> [ <data> ]
     *
     * Example:
     *
     *     5hello world
     *     3
     *     4
     *
     * Binary is encoded in an identical principle
     *
     * @api private
     *
     * @param $packet
     * @param $supportsBinary
     * @param $utf8encode
     * @param $callback
     *
     * @return string
     */
    public static function encodePacket($packet, $supportsBinary = null, $utf8encode = null, $callback = null)
    {
        // is function
        if (is_callable($supportsBinary)) {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }

        // is function
        if (is_callable($utf8encode)) {
            $callback = $utf8encode;
            $utf8encode = null;
        }

        // Sending data as a utf-8 string
        $encoded = PacketEnum::getCodeByText($packet['type']);

        // data fragment is optional
        if (isset($packet['data']) && !empty($packet['data'])) {
            if ($utf8encode === true && strpos($packet['data'], '\u') !== false) {
                // todo : see test: testShouldEncodeAStringMessageWithLoneSurrogatesReplacedByUFFFD
            }

            $encoded .= $utf8encode ? Utf8::encode((is_array($packet['data']) ? implode(',',
                $packet['data']) : $packet['data']),
                ['strict' => false]) : (is_array($packet['data']) ? implode(',', $packet['data']) : $packet['data']);
        }

        return $callback('' . $encoded);
    }


    /**
     * Encode Buffer data
     *
     * @param $packet
     * @param $supportsBinary
     * @param $callback
     *
     * @return string
     */
    public static function encodeBuffer($packet, $supportsBinary, $callback)
    {
        if (!$supportsBinary) {
            return self::encodeBase64Packet($packet, $callback);
        }

        $data = $packet['data'];
        $typeBuffer = chr(PacketEnum::getCodeByText($packet['type']));

        return $callback($typeBuffer . $data);
    }

    /**
     * /
     * Encodes a packet with binary data in a base64 string
     *
     * @param $packet , has `type` and `data`
     * @param $callback
     *
     * @return string base64 encoded message
     */
    public static function encodeBase64Packet($packet, $callback)
    {
        $data = is_array($packet['data']) ? ' arrayBufferToBuffer(packet.data)' : $packet['data'];
        $message = 'b' . PacketEnum::getCodeByText($packet['type']);
        $message .= base64_encode($data);

        return $callback($message);
    }

    /**
     * Decodes a packet. Data also available as an ArrayBuffer if requested.
     *
     * @param $data
     * @param $binaryType
     * @param $utf8decode
     *
     * @return array with `type` and `data` (if any)
     */
    public static function decodePacket($data, $binaryType = null, $utf8decode = null)
    {
        if ($data === null) {
            return self::getErr();
        }

        $type = null;

        // String data
        if (is_string($data)) {
            $type = $data{0};

            // is unicode
            if (preg_match('/^\\\u/', substr($data, 1))) {
                $data = $type . self::unicodeDecode(substr($data, 1));
            }

            if ($type === 'b') {
                return self::decodeBase64Packet(substr($data, 1));
            }

            if ($utf8decode) {
                $data = self::tryDecode($data);
                if ($data === false) {
                    return self::getErr();
                }
            }

            if (self::Number($type) != $type || !isset(self::getPacketList()[$type])) {
                return self::getErr();
            }

            if (strlen($data) > 1) {
                return [
                    'type' => self::getPacketList()[$type],
                    'data' => substr($data, 1)
                ];
            } else {
                return [
                    'type' => self::getPacketList()[$type]
                ];
            }
        }

        $type = $data[0];

        return [
            'type' => self::getPacketList()[$type],
            'data' => array_slice($data, 1)
        ];
    }

    public static function decodeBase64Packet($msg)
    {
        $type = self::getPacketList()[$msg{0}];
        $data = base64_encode(substr($msg, 1));

        return ['type' => $type, 'data' => $data];
    }

    public static function encodePayload($packets, $supportsBinary, $callback = null)
    {
        // is function
        if (is_callable($supportsBinary)) {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }

//        if (supportsBinary && hasBinary(packets)) {
//            return exports.encodePayloadAsBinary(packets, callback);
//        }

        if (!count($packets)) {
            return $callback('0:');
        }

        $encodeOne = function ($packet, $doneCallback) use ($supportsBinary) {
            self::encodePacket($packet, $supportsBinary, false, function ($message) use ($doneCallback) {
                $doneCallback(null, self::setLengthHeader($message));
            });
        };

        self::map($packets, $encodeOne, function ($err, $results) use ($callback) {
            return $callback(implode('', $results));
        });
    }

    private static function setLengthHeader($message)
    {
        return mb_strlen($message) . ':' . $message;
    }

    private static function map($ary, $each, $done)
    {
        $result = [];
        $next = self::after(count($ary), $done);

        for ($i = 0; $i < count($ary); $i++) {
            $each($ary[$i], function ($error, $msg) use ($i, $next, &$result) {
                $result[$i] = $msg;
                $next($error, $result);
            });
        }
    }

    public static function decodePayload($data, $binaryType, $callback = null)
    {
//        if (typeof data !== 'string') {
//        return exports.decodePayloadAsBinary(data, binaryType, callback);
//      }

        if (is_callable($binaryType)) {
            $callback = $binaryType;
            $binaryType = null;
        }

        if ($data === '') {
            // parser error - ignoring payload
            return $callback(self::getErr(), 0, 1);
        }

        $length = $n = $msg = $packet = '';
        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $chr = $data{$i};

            if ($chr !== ':') {
                $length .= $chr;
                continue;
            }

            if ($length === '' || ($length != ($n = self::Number($length)))) {
                return callback(self::getErr(), 0, 1);
            }

            $msg = substr($data, $i + 1, $n);

            if ($length != strlen($msg)) {
                // parser error - ignoring payload
                return $callback(self::getErr(), 0, 1);
            }

            if (strlen($msg) > 0) {
                $packet = self::decodePacket($msg, $binaryType, false);

                if (self::getErr()['type'] === $packet['type'] && self::getErr()['data'] === $packet['data']) {
                    // parser error in individual packet - ignoring payload
                    return $callback(self::getErr(), 0, 1);
                }

                $more = $callback($packet, $i + $n, $l);
                if (false === $more) return null;
            }

            // advance cursor
            $i += $n;
            $length = '';
        }

        if ($length !== '') {
            // parser error - ignoring payload
            return $callback(self::getErr(), 0, 1);
        }

        return null;
    }

    private static function after($count, $callback, $err_cb = null)
    {
        $bail = false;
        $err_cb = empty($err_cb) ? [Parser::class, 'noop'] : $err_cb;
        $proxy = function ($err, $result, $count = null) use (&$bail, &$callback, $err_cb) {
            static $inCount;
            if (!empty($count)) {
                $inCount = $count;

                return;
            }

            if ($inCount === null) {
                $inCount = 0;
            }

            if ($inCount <= 0) {
                throw new \Exception('after called too many times');
            }
            --$inCount;

            if ($err) {
                $bail = true;
                $callback($err);
                $callback = $err_cb;
            } elseif ($inCount === 0 && !$bail) {
                $callback(null, $result);
            }
        };
        $proxy(null, null, $count);

        return ($count === 0) ? $callback : $proxy;
    }

    private static function noop()
    {

    }

    public static function tryDecode($data)
    {
        try {
            $data = Utf8::decode($data, ['strict' => false]);
        } catch (\Exception $e) {
            return false;
        }

        return $data;
    }

    private static function unicodeDecode($unicode_str)
    {
        $json = '{"str":"' . $unicode_str . '"}';
        $arr = json_decode($json, true);
        if (empty($arr)) return '';

        return $arr['str'];
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