/**
 * The networks' sums, shared by the reader of the handwritten cells (recognizer.js) and the
 * reader of the printed text (text.js): 3x3 convolutions (padding 1), 1-D convolutions,
 * dense layers, channels first as the networks were trained (numpy).
 *
 * The convolutions go four output channels at a time, each input value loaded once for
 * the four: a third less time than one channel at a time, the same sums in the same order
 * (the outputs are those of the plain loops, to the bit). Browser and Node alike, no
 * dependency.
 *
 * Where the browser runs WebAssembly with SIMD, the convolutions go through the small module
 * below instead, three to five times faster. Its two functions, addresses in bytes of its own
 * memory (exported "mem"), w4 the width rounded up to four:
 *   conv3(inp, cin, h, P, wts, bias, out, cout, w4), the input zero-padded by one ((h + 2)
 *   rows of P floats):
 *     for co < cout, y < h, x < w4 by 4:
 *       acc = splat(bias[co])
 *       for ci < cin, ky < 3, kx < 3:
 *         acc += load4(inp[ci][y + ky][x + kx]) * splat(wts[co][ci][ky][kx])
 *       store4(out[co][y][x], acc)
 *   conv1(inp, cin, Wp, wts, bias, out, cout, w4, k), the input zero-padded by k >> 1 (rows
 *   of Wp floats): the same along one row, over ci < cin and kk < k.
 * Its sums are in single precision, each product rounded before it is added: the outputs
 * differ from the loops' by about a millionth of their size. Written out byte by byte (no
 * compiler involved); a browser without SIMD (Safari before 16.4) keeps the loops.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrNN = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var WASM_CONV = [
        0,97,115,109,1,0,0,0,1,13,1,96,9,127,127,127,127,127,127,127,127,127,0,3,3,2,0,0,5,3,1,0,1,7,23,
        3,5,99,111,110,118,51,0,0,5,99,111,110,118,49,0,1,3,109,101,109,2,0,10,143,4,2,173,2,2,9,127,1,
        123,65,0,33,9,2,64,3,64,32,9,32,7,78,13,1,65,0,33,10,2,64,3,64,32,10,32,2,78,13,1,65,0,33,11,2,
        64,3,64,32,11,32,8,78,13,1,32,5,32,9,65,2,116,106,42,2,0,253,19,33,18,32,4,32,9,32,1,108,65,36,
        108,106,33,15,65,0,33,12,2,64,3,64,32,12,32,1,78,13,1,65,0,33,13,2,64,3,64,32,13,65,3,78,13,1,
        32,0,32,12,32,2,65,2,106,108,32,10,106,32,13,106,32,3,108,32,11,106,65,2,116,106,33,14,32,18,32,
        14,253,0,0,0,32,15,42,2,0,253,19,253,230,1,253,228,1,33,18,32,18,32,14,253,0,0,4,32,15,42,2,4,
        253,19,253,230,1,253,228,1,33,18,32,18,32,14,253,0,0,8,32,15,42,2,8,253,19,253,230,1,253,228,1,
        33,18,32,15,65,12,106,33,15,32,13,65,1,106,33,13,12,0,11,11,32,12,65,1,106,33,12,12,0,11,11,32,
        6,32,9,32,2,108,32,10,106,32,8,108,32,11,106,65,2,116,106,32,18,253,11,0,0,32,11,65,3,106,33,11,
        32,11,65,1,106,33,11,12,0,11,11,32,10,65,1,106,33,10,12,0,11,11,32,9,65,1,106,33,9,12,0,11,11,
        11,221,1,2,6,127,1,123,65,0,33,9,2,64,3,64,32,9,32,6,78,13,1,65,0,33,10,2,64,3,64,32,10,32,7,78,
        13,1,32,4,32,9,65,2,116,106,42,2,0,253,19,33,15,32,3,32,9,32,1,108,32,8,108,65,2,116,106,33,14,
        65,0,33,11,2,64,3,64,32,11,32,1,78,13,1,32,0,32,11,32,2,108,32,10,106,65,2,116,106,33,13,65,0,
        33,12,2,64,3,64,32,12,32,8,78,13,1,32,15,32,13,253,0,0,0,32,14,42,2,0,253,19,253,230,1,253,228,
        1,33,15,32,13,65,4,106,33,13,32,14,65,4,106,33,14,32,12,65,1,106,33,12,12,0,11,11,32,11,65,1,
        106,33,11,12,0,11,11,32,5,32,9,32,7,108,32,10,106,65,2,116,106,32,15,253,11,0,0,32,10,65,3,106,
        33,10,32,10,65,1,106,33,10,12,0,11,11,32,9,65,1,106,33,9,12,0,11,11,11
    ];

    var wasm = (function () {
        try {
            var bytes = new Uint8Array(WASM_CONV);
            if (typeof WebAssembly !== 'object' || !WebAssembly.validate(bytes)) return null;
            var inst = new WebAssembly.Instance(new WebAssembly.Module(bytes), {});
            return {conv3: inst.exports.conv3, conv1: inst.exports.conv1, mem: inst.exports.mem};
        } catch (e) {
            return null;
        }
    })();

    /** The 3x3 convolution in the module: the input copied in, padded, the output copied out. */
    function conv3Wasm(x, cin, cout, h, w, W, b) {
        var w4 = (w + 3) & ~3, P = w4 + 2, H2 = h + 2, inF = cin * H2 * P, wF = cout * cin * 9, outF = cout * h * w4;
        var need = (inF + wF + cout + outF) * 4, mem = wasm.mem, ci, y, co;
        if (mem.buffer.byteLength < need) mem.grow(Math.ceil((need - mem.buffer.byteLength) / 65536));
        var f = new Float32Array(mem.buffer), wOff = inF, bOff = wOff + wF, oOff = bOff + cout;
        f.fill(0, 0, inF);
        for (ci = 0; ci < cin; ci++) {
            for (y = 0; y < h; y++) {
                var s = ci * h * w + y * w;
                f.set(x.subarray(s, s + w), (ci * H2 + y + 1) * P + 1);
            }
        }
        f.set(W, wOff);
        f.set(b, bOff);
        wasm.conv3(0, cin, h, P, wOff * 4, bOff * 4, oOff * 4, cout, w4);
        var out = new Float32Array(cout * h * w);
        for (co = 0; co < cout; co++) {
            for (y = 0; y < h; y++) {
                var o = oOff + (co * h + y) * w4;
                out.set(f.subarray(o, o + w), (co * h + y) * w);
            }
        }
        return out;
    }

    /** The 1-D convolution in the module: likewise. */
    function conv1Wasm(x, cin, cout, w, k, W, b) {
        var r = k >> 1, w4 = (w + 3) & ~3, Wp = w4 + k - 1, inF = cin * Wp, wF = cout * cin * k, outF = cout * w4;
        var need = (inF + wF + cout + outF) * 4, mem = wasm.mem, ci, co;
        if (mem.buffer.byteLength < need) mem.grow(Math.ceil((need - mem.buffer.byteLength) / 65536));
        var f = new Float32Array(mem.buffer), wOff = inF, bOff = wOff + wF, oOff = bOff + cout;
        f.fill(0, 0, inF);
        for (ci = 0; ci < cin; ci++) f.set(x.subarray(ci * w, ci * w + w), ci * Wp + r);
        f.set(W, wOff);
        f.set(b, bOff);
        wasm.conv1(0, cin, Wp, wOff * 4, bOff * 4, oOff * 4, cout, w4, k);
        var out = new Float32Array(cout * w);
        for (co = 0; co < cout; co++) out.set(f.subarray(oOff + co * w4, oOff + co * w4 + w), co * w);
        return out;
    }

    /**
     * 3x3 convolution, padding 1: out[co][y][x] = b[co] + sum over ci, ky, kx of
     * W[co][ci][ky][kx] * x[ci][y + ky - 1][x + kx - 1].
     *
     * @param {Float32Array} x Input, cin planes of h x w.
     * @return {Float32Array} cout planes of h x w.
     */
    function conv3(x, cin, cout, h, w, W, b) {
        if (wasm && useWasm) return conv3Wasm(x, cin, cout, h, w, W, b);
        var n = h * w, out = new Float32Array(cout * n), s = cin * 9, co, i;
        for (co = 0; co < cout; co += 4) {
            var m = Math.min(4, cout - co);
            for (var j = 0; j < m; j++) {
                var oj = (co + j) * n, bj = b[co + j];
                for (i = 0; i < n; i++) out[oj + i] = bj;
            }
            if (m === 4) conv3Four(x, cin, h, w, W, out, co, s);
            else for (j = 0; j < m; j++) conv3One(x, cin, h, w, W, out, co + j, s);
        }
        return out;
    }

    function conv3Four(x, cin, h, w, W, out, co, s) {
        var n = h * w, o0 = co * n, o1 = o0 + n, o2 = o1 + n, o3 = o2 + n;
        for (var ci = 0; ci < cin; ci++) {
            var src = ci * n;
            for (var ky = 0; ky < 3; ky++) {
                var dy = ky - 1, y0 = Math.max(0, -dy), y1 = Math.min(h, h - dy);
                for (var kx = 0; kx < 3; kx++) {
                    var dx = kx - 1, x0 = Math.max(0, -dx), x1 = Math.min(w, w - dx), k = (ci * 3 + ky) * 3 + kx;
                    var w0 = W[co * s + k], w1 = W[(co + 1) * s + k], w2 = W[(co + 2) * s + k], w3 = W[(co + 3) * s + k];
                    if (w0 === 0 && w1 === 0 && w2 === 0 && w3 === 0) continue;
                    for (var y = y0; y < y1; y++) {
                        var row = y * w, srow = src + (y + dy) * w + dx;
                        for (var xx = x0; xx < x1; xx++) {
                            var v = x[srow + xx], p = row + xx;
                            out[o0 + p] += w0 * v;
                            out[o1 + p] += w1 * v;
                            out[o2 + p] += w2 * v;
                            out[o3 + p] += w3 * v;
                        }
                    }
                }
            }
        }
    }

    function conv3One(x, cin, h, w, W, out, co, s) {
        var n = h * w, o = co * n;
        for (var ci = 0; ci < cin; ci++) {
            var src = ci * n;
            for (var ky = 0; ky < 3; ky++) {
                var dy = ky - 1, y0 = Math.max(0, -dy), y1 = Math.min(h, h - dy);
                for (var kx = 0; kx < 3; kx++) {
                    var dx = kx - 1, wv = W[co * s + (ci * 3 + ky) * 3 + kx];
                    if (wv === 0) continue;
                    var x0 = Math.max(0, -dx), x1 = Math.min(w, w - dx);
                    for (var y = y0; y < y1; y++) {
                        var row = o + y * w, srow = src + (y + dy) * w + dx;
                        for (var xx = x0; xx < x1; xx++) out[row + xx] += wv * x[srow + xx];
                    }
                }
            }
        }
    }

    /**
     * 1-D convolution of width k (odd), padding k >> 1: out[co][t] = b[co] + sum over ci,
     * kk of W[co][ci][kk] * x[ci][t + kk - (k >> 1)].
     *
     * @param {Float32Array} x Input, cin rows of w.
     * @return {Float32Array} cout rows of w.
     */
    function conv1(x, cin, cout, w, k, W, b) {
        if (wasm && useWasm) return conv1Wasm(x, cin, cout, w, k, W, b);
        var out = new Float32Array(cout * w), r = k >> 1, s = cin * k, co, i;
        for (co = 0; co < cout; co += 4) {
            var m = Math.min(4, cout - co);
            for (var j = 0; j < m; j++) {
                var oj = (co + j) * w, bj = b[co + j];
                for (i = 0; i < w; i++) out[oj + i] = bj;
            }
            for (var ci = 0; ci < cin; ci++) {
                var src = ci * w;
                for (var kk = 0; kk < k; kk++) {
                    var d = kk - r, x0 = Math.max(0, -d), x1 = Math.min(w, w - d), at = ci * k + kk;
                    if (m === 4) {
                        var w0 = W[co * s + at], w1 = W[(co + 1) * s + at], w2 = W[(co + 2) * s + at], w3 = W[(co + 3) * s + at];
                        if (w0 === 0 && w1 === 0 && w2 === 0 && w3 === 0) continue;
                        var o0 = co * w, o1 = o0 + w, o2 = o1 + w, o3 = o2 + w;
                        for (var t = x0; t < x1; t++) {
                            var v = x[src + t + d];
                            out[o0 + t] += w0 * v;
                            out[o1 + t] += w1 * v;
                            out[o2 + t] += w2 * v;
                            out[o3 + t] += w3 * v;
                        }
                    } else {
                        for (j = 0; j < m; j++) {
                            var wv = W[(co + j) * s + at];
                            if (wv === 0) continue;
                            var oo = (co + j) * w;
                            for (t = x0; t < x1; t++) out[oo + t] += wv * x[src + t + d];
                        }
                    }
                }
            }
        }
        return out;
    }

    /**
     * Dense layer: out[j] = b[j] + sum over i of x[i] * W[i][j] (W rows by input).
     *
     * @return {Float32Array} nout values.
     */
    function dense(x, nin, nout, W, b) {
        var out = new Float32Array(nout), i, j;
        for (j = 0; j < nout; j++) out[j] = b[j];
        for (i = 0; i < nin; i++) {
            var v = x[i];
            if (!v) continue;
            var r = i * nout;
            for (j = 0; j < nout; j++) out[j] += v * W[r + j];
        }
        return out;
    }

    // The loops always, when asked (a bench comparing them; useWasm(false)). -> whether the module runs.
    var useWasm = true;

    function setWasm(on) {
        useWasm = !!on;
        return !!wasm && useWasm;
    }

    return {conv3: conv3, conv1: conv1, dense: dense, useWasm: setWasm, simd: !!wasm};
});
