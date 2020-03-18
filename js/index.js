var parser = require('socket.io-parser');

var encoder = new parser.Encoder;

var obj = {
    type: parser.ERROR,
    data: 'Unauthorized',
    nsp: '/'
};

var decoder = new parser.Decoder();
decoder.add('5');

// encoder.encode(obj, function(encodedPackets) {
//     console.log(encodedPackets);
//     var decoder = new parser.Decoder();
//     decoder.on('decoded', function(packet) {
//         console.log(packet)
//     });
//
//     decoder.add(encodedPackets[0]);
// });
