import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Guarda el token Sanctum asociado a una sesión del PJ, para seguir funcionando si el endpoint de
/// identidad del PJ se cae (spec 9) o si la app abre sin señal (plan sin señal). La clave es el SHA-256 del
/// token del PJ: otro usuario (u otra sesión) del mismo dispositivo no puede reutilizarlo. Junto al token va
/// el usuario de esa sesión (`GET /yo`), para abrir sin señal sin preguntarle al servidor quién es.
abstract interface class AlmacenToken {
  Future<String?> leer(String tokenPJ);

  /// El usuario guardado con el token de [tokenPJ] (como lo devuelve `Usuario.toJson`), o nulo.
  Future<Map<String, dynamic>?> leerUsuario(String tokenPJ);

  Future<void> guardar(String tokenPJ, String tokenSanctum, {Map<String, dynamic>? usuario});

  Future<void> borrar();
}

String claveDeSesion(String tokenPJ) => sha256.convert(utf8.encode(tokenPJ)).toString();

class AlmacenTokenSeguro implements AlmacenToken {
  AlmacenTokenSeguro([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const _claveSesion = 'vehiculos_oficiales.sesion';
  static const _claveToken = 'vehiculos_oficiales.token';
  static const _claveUsuario = 'vehiculos_oficiales.usuario';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> leer(String tokenPJ) async {
    if (await _storage.read(key: _claveSesion) != claveDeSesion(tokenPJ)) return null;
    return _storage.read(key: _claveToken);
  }

  @override
  Future<Map<String, dynamic>?> leerUsuario(String tokenPJ) async {
    if (await _storage.read(key: _claveSesion) != claveDeSesion(tokenPJ)) return null;
    final usuario = await _storage.read(key: _claveUsuario);
    return usuario == null ? null : (jsonDecode(usuario) as Map).cast<String, dynamic>();
  }

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum, {Map<String, dynamic>? usuario}) async {
    await _storage.write(key: _claveSesion, value: claveDeSesion(tokenPJ));
    await _storage.write(key: _claveToken, value: tokenSanctum);
    if (usuario == null) {
      await _storage.delete(key: _claveUsuario);
    } else {
      await _storage.write(key: _claveUsuario, value: jsonEncode(usuario));
    }
  }

  @override
  Future<void> borrar() async {
    await _storage.delete(key: _claveSesion);
    await _storage.delete(key: _claveToken);
    await _storage.delete(key: _claveUsuario);
  }
}

/// Para tests y para quien no quiera persistir nada.
class AlmacenTokenMemoria implements AlmacenToken {
  String? _clave;
  String? _token;
  Map<String, dynamic>? _usuario;

  @override
  Future<String?> leer(String tokenPJ) async => _clave == claveDeSesion(tokenPJ) ? _token : null;

  @override
  Future<Map<String, dynamic>?> leerUsuario(String tokenPJ) async => _clave == claveDeSesion(tokenPJ) ? _usuario : null;

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum, {Map<String, dynamic>? usuario}) async {
    _clave = claveDeSesion(tokenPJ);
    _token = tokenSanctum;
    _usuario = usuario;
  }

  @override
  Future<void> borrar() async => _clave = _token = _usuario = null;
}
