import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Guarda el token Sanctum asociado a una sesión del PJ, para seguir funcionando si el endpoint de
/// identidad del PJ se cae (spec 9). La clave es el SHA-256 del token del PJ: otro usuario (u otra
/// sesión) del mismo dispositivo no puede reutilizarlo.
abstract interface class AlmacenToken {
  Future<String?> leer(String tokenPJ);

  Future<void> guardar(String tokenPJ, String tokenSanctum);

  Future<void> borrar();
}

String claveDeSesion(String tokenPJ) => sha256.convert(utf8.encode(tokenPJ)).toString();

class AlmacenTokenSeguro implements AlmacenToken {
  AlmacenTokenSeguro([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const _claveSesion = 'vehiculos_oficiales.sesion';
  static const _claveToken = 'vehiculos_oficiales.token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> leer(String tokenPJ) async {
    if (await _storage.read(key: _claveSesion) != claveDeSesion(tokenPJ)) return null;
    return _storage.read(key: _claveToken);
  }

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum) async {
    await _storage.write(key: _claveSesion, value: claveDeSesion(tokenPJ));
    await _storage.write(key: _claveToken, value: tokenSanctum);
  }

  @override
  Future<void> borrar() async {
    await _storage.delete(key: _claveSesion);
    await _storage.delete(key: _claveToken);
  }
}

/// Para tests y para quien no quiera persistir nada.
class AlmacenTokenMemoria implements AlmacenToken {
  String? _clave;
  String? _token;

  @override
  Future<String?> leer(String tokenPJ) async => _clave == claveDeSesion(tokenPJ) ? _token : null;

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum) async {
    _clave = claveDeSesion(tokenPJ);
    _token = tokenSanctum;
  }

  @override
  Future<void> borrar() async => _clave = _token = null;
}
