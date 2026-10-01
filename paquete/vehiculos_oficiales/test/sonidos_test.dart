import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  for (final sonido in Sonido.values) {
    test('${sonido.name}: WAV PCM 16 bits mono 22050 Hz, chico y declarado como asset del paquete', () async {
      final archivo = File('assets/sonidos/${sonido.archivo}');
      expect(archivo.lengthSync(), lessThan(100 * 1024));
      final b = ByteData.sublistView(archivo.readAsBytesSync());
      expect(String.fromCharCodes(archivo.readAsBytesSync().sublist(0, 4)), 'RIFF');
      expect(b.getUint16(20, Endian.little), 1); // PCM
      expect(b.getUint16(22, Endian.little), 1); // mono
      expect(b.getUint32(24, Endian.little), 22050);
      expect(b.getUint16(34, Endian.little), 16);

      // Así lo pide audioplayers: el prefijo de los assets de un paquete más el archivo.
      final asset = await rootBundle.load('${ReproductorAudioplayers.prefijo}${sonido.archivo}');
      expect(asset.lengthInBytes, archivo.lengthSync());
    });
  }
}
