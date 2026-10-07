import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/sesion/almacen_token.dart';

/// Almacén seguro en memoria que registra cada escritura y borrado, en orden.
class _AlmacenRegistrado implements FlutterSecureStorage {
  final valores = <String, String>{};
  final operaciones = <String>[];

  @override
  dynamic noSuchMethod(Invocation i) {
    final clave = i.namedArguments[#key] as String;
    switch (i.memberName) {
      case #read:
        return Future<String?>.value(valores[clave]);
      case #write:
        operaciones.add('escribir $clave');
        valores[clave] = i.namedArguments[#value] as String;
        return Future<void>.value();
      case #delete:
        operaciones.add('borrar $clave');
        valores.remove(clave);
        return Future<void>.value();
    }
    return super.noSuchMethod(i);
  }
}

void main() {
  test('la clave de la sesión se borra primero y se escribe al final (cortado a mitad, nada queda mezclado)', () async {
    final registro = _AlmacenRegistrado();
    final almacen = AlmacenTokenSeguro(registro);

    await almacen.guardar('pj', '7|token', usuario: {'id': 2, 'nombre': 'Carlos', 'cargo': null, 'rol': 'chofer'});

    expect(registro.operaciones, [
      'borrar vehiculos_oficiales.sesion',
      'escribir vehiculos_oficiales.token',
      'escribir vehiculos_oficiales.usuario',
      'escribir vehiculos_oficiales.sesion',
    ]);
    expect(await almacen.leer('pj'), '7|token');
    expect((await almacen.leerUsuario('pj'))!['id'], 2);
    expect(await almacen.leer('otro'), isNull);
  });
}
