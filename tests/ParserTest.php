<?php

namespace SocketIoParserTest;

use PHPUnit\Framework\TestCase;
use SocketIoParser\Enums\PacketEnum;
use SocketIoParser\Parser;

final class ParserTest extends TestCase
{
    public function testEncodePacketConnect()
    {
        $data = [
            'type' => PacketEnum::CONNECT,
            'nsp'  => '/woot'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            Parser::on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodePacketDisconnect()
    {
        $data = [
            'type' => PacketEnum::DISCONNECT,
            'nsp'  => '/woot'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $emitter = Parser::getEmitter(true);
            $emitter->on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodePacketEvent()
    {
        $data = [
            'type' => PacketEnum::EVENT,
            'data' => ['a', 1, []],
            'nsp'  => '/woot'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $emitter = Parser::getEmitter(true);
            $emitter->on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodePacketEventHasId()
    {
        $data = [
            'type' => PacketEnum::EVENT,
            'data' => ['a', 1, []],
            'id'   => 1,
            'nsp'  => '/woot'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $emitter = Parser::getEmitter(true);
            $emitter->on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodeAck()
    {
        $data = [
            'type' => PacketEnum::ACK,
            'data' => ['a', 1, []],
            'id'   => 123,
            'nsp'  => '/'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $emitter = Parser::getEmitter(true);
            $emitter->on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodeError()
    {
        $data = [
            'type' => PacketEnum::ERROR,
            'data' => 'Unauthorized',
            'nsp'  => '/'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $emitter = Parser::getEmitter(true);
            $emitter->on('decoded', function ($packet) use ($data) {
                $this->assertEquals($data, $packet);
            });

            Parser::add($encodedPackets[0]);
        });
    }

    public function testEncodeError2()
    {
        $data = [
            'type' => PacketEnum::ERROR,
            'data' => new \stdClass(),
            'id'   => 1,
            'nsp'  => '/'
        ];

        Parser::encode($data, function ($encodedPackets) use ($data) {
            $this->assertEquals($encodedPackets[0], '4"encode error"');
        });
    }

    public function testEncodeError3()
    {
        try {
            Parser::add('5');
        } catch (\Exception $e) {
            $this->assertEquals(1, preg_match('/Illegal/', $e->getMessage()));
        }
    }

    public function testEncodeError4()
    {
        $emitter = Parser::getEmitter(true);
        $emitter->on('decoded', function ($packet) {
            $this->assertEquals(['type' => 4, 'data' => 'parser error: invalid payload'], $packet);
        });

        Parser::add('442["some","data"');
    }

}