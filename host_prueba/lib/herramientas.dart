import 'package:flutter/material.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'config_host.dart';
import 'login_falso.dart';
import 'puente_falso.dart';

/// Sección "Herramientas" de la app principal, con el ítem que abre el módulo (spec 12.2).
class Herramientas extends StatefulWidget {
  const Herramientas({super.key, required this.tokenSesion, this.config = configHost});

  final String tokenSesion;
  final VehiculosOficialesConfig config;

  @override
  State<Herramientas> createState() => _HerramientasState();
}

class _HerramientasState extends State<Herramientas> {
  final _push = PuenteNotificacionesFalso();

  void _cerrarSesion({String? aviso}) {
    final navegador = Navigator.of(context);
    final mensajero = ScaffoldMessenger.of(context);
    navegador.popUntil((r) => r.isFirst);
    navegador.pushReplacement(MaterialPageRoute<void>(builder: (_) => const LoginFalso()));
    if (aviso != null) mensajero.showSnackBar(SnackBar(content: Text(aviso)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Herramientas'),
        actions: [IconButton(icon: const Icon(Icons.logout), tooltip: 'Cerrar sesión', onPressed: _cerrarSesion)],
      ),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.directions_car),
            title: const Text('Vehículos oficiales'),
            subtitle: Text(widget.tokenSesion),
            onTap: () => VehiculosOficiales.abrir(
              context,
              sesion: SesionPJ(widget.tokenSesion),
              push: _push,
              onSesionInvalida: () => _cerrarSesion(aviso: 'Tu sesión venció. Volvé a ingresar.'),
              config: widget.config,
            ),
          ),
          ListTile(
            leading: const Icon(Icons.notifications),
            title: const Text('Simular push "viaje"'),
            subtitle: const Text('Como si FCM trajera un cambio de estado'),
            onTap: () => _push.simular({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje'}),
          ),
          ListTile(
            leading: const Icon(Icons.badge),
            title: const Text('Simular push "turno"'),
            subtitle: const Text('Como si el fichaje abriera o cerrara el turno del chofer'),
            onTap: () => _push.simular({'modulo': 'vehiculos_oficiales', 'tipo': 'turno', 'estado': 'abierto'}),
          ),
        ],
      ),
    );
  }
}
