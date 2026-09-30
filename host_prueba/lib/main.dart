import 'package:flutter/material.dart';

import 'login_falso.dart';

void main() => runApp(const HostPrueba());

/// Simula a la app del Poder Judicial: login falso y la sección "Herramientas".
class HostPrueba extends StatelessWidget {
  const HostPrueba({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'App PJ (prueba)',
      theme: ThemeData(colorSchemeSeed: const Color(0xFF1B4F72)),
      home: const LoginFalso(),
    );
  }
}
