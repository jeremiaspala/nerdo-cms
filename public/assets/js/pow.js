/**
 * Prueba de trabajo del lado del navegador.
 *
 * La usan los dos formularios públicos: comentarios y contacto. Está acá y no
 * duplicada en cada uno porque el SHA-256 son cincuenta líneas que no se
 * revisan dos veces.
 *
 * El trato es: el servidor emite un desafío firmado (`nonce` + dificultad en
 * bits) y el navegador busca un contador tal que sha256(nonce:contador)
 * empiece con esos bits en cero. Verificar cuesta un hash; resolver cuesta
 * 2^bits. Al visitante le lleva medio segundo y lo hace una vez; a quien
 * quiera mandar diez mil mensajes le lleva diez mil veces eso.
 *
 * ── Por qué un SHA-256 escrito a mano y no crypto.subtle ──
 *
 * crypto.subtle.digest es asíncrono: devuelve una promesa por hash. A 18 bits
 * hacen falta ~262.000 intentos, y 262.000 promesas cuestan muchísimo más que
 * los hashes en sí — son varios segundos contra menos de uno. Con mensajes de
 * un solo bloque (el nonce son 32 caracteres y el contador unos pocos), la
 * compresión entra en cincuenta líneas y corre sincrónica.
 */
(function () {
  'use strict';

  var K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ];

  var W = new Int32Array(64);
  var block = new Uint8Array(64);

  /**
   * Primera palabra del digest del bloque que haya en `block`.
   * Alcanza: la dificultad nunca pasa de 24 bits, que entran en 32.
   */
  function firstWord() {
    var i, t1, t2, s0, s1, ch, maj, ep0, ep1;

    for (i = 0; i < 16; i++) {
      W[i] = (block[i * 4] << 24) | (block[i * 4 + 1] << 16) | (block[i * 4 + 2] << 8) | block[i * 4 + 3];
    }

    for (i = 16; i < 64; i++) {
      t1 = W[i - 15];
      s0 = ((t1 >>> 7) | (t1 << 25)) ^ ((t1 >>> 18) | (t1 << 14)) ^ (t1 >>> 3);
      t2 = W[i - 2];
      s1 = ((t2 >>> 17) | (t2 << 15)) ^ ((t2 >>> 19) | (t2 << 13)) ^ (t2 >>> 10);
      W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
    }

    var a = 0x6a09e667, b = 0xbb67ae85, c = 0x3c6ef372, d = 0xa54ff53a;
    var e = 0x510e527f, f = 0x9b05688c, g = 0x1f83d9ab, h = 0x5be0cd19;

    for (i = 0; i < 64; i++) {
      ep1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
      ch = (e & f) ^ (~e & g);
      t1 = (h + ep1 + ch + K[i] + W[i]) | 0;

      ep0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
      maj = (a & b) ^ (a & c) ^ (b & c);
      t2 = (ep0 + maj) | 0;

      h = g; g = f; f = e; e = (d + t1) | 0;
      d = c; c = b; b = a; a = (t1 + t2) | 0;
    }

    return ((0x6a09e667 + a) | 0) >>> 0;
  }

  /** Arma el bloque con `prefix + counter` y devuelve la primera palabra. */
  function hashOf(prefixLength, counter) {
    var digits = '' + counter;
    var len = prefixLength + digits.length;
    var i;

    for (i = 0; i < digits.length; i++) {
      block[prefixLength + i] = digits.charCodeAt(i);
    }

    block[len] = 0x80;
    for (i = len + 1; i < 61; i++) { block[i] = 0; }

    // Longitud en bits, big-endian. Con menos de 56 bytes de mensaje, los
    // cinco bytes anteriores del campo siempre son cero.
    var bits = len * 8;
    block[61] = (bits >>> 16) & 0xff;
    block[62] = (bits >>> 8) & 0xff;
    block[63] = bits & 0xff;

    return firstWord();
  }

  var CHUNK = 20000;

  /**
   * Busca la solución en tandas, cediendo el hilo entre una y otra: la página
   * tiene que seguir respondiendo mientras la persona escribe.
   */
  function solve(nonce, bits, done) {
    var prefix = nonce + ':';
    var shift = 32 - bits;
    var i;

    for (i = 0; i < prefix.length; i++) { block[i] = prefix.charCodeAt(i); }

    var counter = 0;
    var t0 = Date.now();

    function round() {
      var end = counter + CHUNK;

      for (; counter < end; counter++) {
        if ((hashOf(prefix.length, counter) >>> shift) === 0) {
          done(String(counter), Date.now() - t0);
          return;
        }
      }

      setTimeout(round, 0);
    }

    round();
  }

  /**
   * Pide un desafío y lo resuelve.
   *
   * @param  {string} url  endpoint que emite el desafío
   * @return {Promise<{token:string, pow:string, ms:number}>}
   */
  function prepare(url) {
    return fetch(url, {
      headers: { 'Accept': 'application/json' },
      credentials: 'omit'
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok) { throw new Error(json.error || 'No se pudo preparar el formulario.'); }
        return json;
      });
    }).then(function (json) {
      return new Promise(function (resolve) {
        solve(json.nonce, json.bits, function (pow, ms) {
          resolve({ token: json.token, pow: pow, ms: ms });
        });
      });
    });
  }

  window.ncmsPow = { solve: solve, prepare: prepare };
})();
