/**
 * Class representing AI/Image.
 *
 * This description should be revised and expanded.
 *
 * @module AI
 */
var Q = require('Q');
var fs = require('fs');

Q.makeEventEmitter(AI_Image);

/**
 * Class Image
 * @namespace AI
 * @class Image
 * @constructor
 */
function AI_Image () {}

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
AI_Image.prototype.setUp = function () {
    // put any code here
};

AI_Image.estimateFaces = function (imagePath, callback) {
    if (!fs.existsSync(imagePath)) {
        return console.warn("file not exists: " + imagePath);
    }

    var tfNode = null;
    try {
        tfNode = require('@tensorflow/tfjs-node');
    } catch (e) {}
    var faceAPI = null;
    try {
        // need node_modules face-api.js and canvas installed
        faceAPI = require('face-api.js');
    } catch (e) {}

    if (faceAPI) {
        var canvas = require('canvas');

        // face-api.js use his own tensorflow (v. 1.7.0) located in node_modules/face-api.js/node_modules/@tensorflow
        // face-api.js will not work with higher versions
        const { Canvas, Image, ImageData } = canvas;
        faceAPI.env.monkeyPatch({ Canvas, Image, ImageData });
        faceAPI.nets.ssdMobilenetv1.loadFromDisk('../../web/Q/plugins/Streams/js/face-api/weights').then(function () {
            canvas.loadImage(imagePath).then(function (image) {
                faceAPI.detectAllFaces(image, new faceAPI.SsdMobilenetv1Options({ minConfidence: 0.2 })).then(function (predictions) {
                    var res = [];
                    predictions.forEach(function (prediction) {
                        var x1 = Math.round(prediction._box._x);
                        var y1 = Math.round(prediction._box._y);
                        var x2 = Math.round(x1 + Math.round(prediction._box._width));
                        var y2 = Math.round(y1 + Math.round(prediction._box._height));
                        res.push({
                            topLeft: [x1, y1],
                            bottomRight: [x2, y2]
                        });
                    });

                    Q.handle(callback, null, [res]);
                    console.log("predictions", res);
                });
            });
        });
        return;
    }

    var tfFaceDetection = require('@tensorflow-models/face-detection');
    var tfCore = require('@tensorflow/tfjs-core');
    require('@tensorflow/tfjs-converter');
    /*
    *   @param backendName The name of the backend. Currently supports
    *     `'webgl'|'cpu'` in the browser, `'tensorflow'` under node.js
    *     (requires tfjs-node), and `'wasm'` (requires tfjs-backend-wasm).
    */

    try {
        tfCore.setBackend(tfNode ? "tensorflow" : "wasm").then(function () {
            tfFaceDetection.load().then(function (model) {
                fs.readFile(imagePath, (err, data) => {
                    if (err) {
                        return console.log(err);
                    }

                    // tf.Tensor3D | ImageData | HTMLVideoElement | HTMLImageElement | HTMLCanvasElement
                    model.estimateFaces(tfNode.node.decodeImage(data, 3)).then(function (predictions) {
                        var res = [];
                        predictions.forEach(function (prediction) {
                            res.push({
                                topLeft: [Math.round(prediction.topLeft[0]), Math.round(prediction.topLeft[1])],
                                bottomRight: [Math.round(prediction.bottomRight[0]), Math.round(prediction.bottomRight[1])]
                            });
                        });
                        Q.handle(callback, null, [res]);
                        console.log("predictions", res);
                    }).catch(function (err) {
                        console.error(err);
                    });
                });
            }).catch(function (error) {
                console.error(error);
            });
        });
    } catch (e) {
        return console.warn(e);
    }
}

module.exports = AI_Image;