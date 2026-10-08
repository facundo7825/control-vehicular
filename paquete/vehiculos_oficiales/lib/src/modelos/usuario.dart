import 'json.dart';

enum Rol {
  solicitante,
  chofer,
  admin;

  static Rol desde(String valor) => Rol.values.byName(valor);
}

/// `usuario` de `POST /auth/intercambio` y respuesta de `GET /yo`.
class Usuario {
  const Usuario({required this.id, required this.nombre, this.cargo, required this.rol});

  factory Usuario.fromJson(Json j) => Usuario(
    id: j['id'] as int,
    nombre: j['nombre'] as String,
    cargo: j['cargo'] as String?,
    rol: Rol.desde(j['rol'] as String),
  );

  final int id;
  final String nombre;
  final String? cargo;
  final Rol rol;

  /// El admin usa la app como solicitante (las rutas de pedido admiten `rol:solicitante,admin`).
  bool get esChofer => rol == Rol.chofer;

  /// Lo que lee [Usuario.fromJson] (para abrir sin señal con el usuario de la última sesión).
  Json toJson() => {'id': id, 'nombre': nombre, 'cargo': cargo, 'rol': rol.name};
}
