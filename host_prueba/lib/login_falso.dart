import 'package:flutter/material.dart';

import 'herramientas.dart';

/// Token que acepta el backend con `IDENTIDAD_DRIVER=simulada` (IdentidadSimulada): `sim|<id>|<nombre>|<cargo>`.
String tokenSimulado({required String id, required String nombre, required String cargo}) =>
    'sim|${id.trim()}|${nombre.trim()}|${cargo.trim()}';

class _Perfil {
  const _Perfil(this.id, this.nombre, this.cargo);

  final String id;
  final String nombre;
  final String cargo;
}

/// Perfiles de ejemplo. El rol de chofer lo asigna un admin en el panel (spec 8): la primera vez que
/// "Carlos Chofer" entra queda como solicitante hasta que se lo cambien. "Juez" genera viajes obligatorios
/// si el cargo está marcado en Cargos prioritarios.
const _perfiles = [
  _Perfil('100', 'Ana Pérez', 'Secretaria'),
  _Perfil('101', 'Jorge Juez', 'Juez'),
  _Perfil('200', 'Carlos Chofer', 'Chofer'),
];

class LoginFalso extends StatefulWidget {
  const LoginFalso({super.key});

  @override
  State<LoginFalso> createState() => _LoginFalsoState();
}

class _LoginFalsoState extends State<LoginFalso> {
  final _form = GlobalKey<FormState>();
  final _id = TextEditingController(text: _perfiles.first.id);
  final _nombre = TextEditingController(text: _perfiles.first.nombre);
  final _cargo = TextEditingController(text: _perfiles.first.cargo);

  @override
  void dispose() {
    _id.dispose();
    _nombre.dispose();
    _cargo.dispose();
    super.dispose();
  }

  String? _validar(String? v) {
    if (v == null || v.trim().isEmpty) return 'Obligatorio';
    if (v.contains('|')) return 'No puede tener "|"';
    return null;
  }

  void _entrar() {
    if (!_form.currentState!.validate()) return;
    final token = tokenSimulado(id: _id.text, nombre: _nombre.text, cargo: _cargo.text);
    Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => Herramientas(tokenSesion: token)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('App del Poder Judicial (prueba)')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Wrap(
              spacing: 8,
              children: [
                for (final p in _perfiles)
                  ActionChip(
                    label: Text(p.nombre),
                    onPressed: () => setState(() {
                      _id.text = p.id;
                      _nombre.text = p.nombre;
                      _cargo.text = p.cargo;
                    }),
                  ),
              ],
            ),
            TextFormField(
              controller: _id,
              decoration: const InputDecoration(labelText: 'Id externo'),
              validator: _validar,
            ),
            TextFormField(
              controller: _nombre,
              decoration: const InputDecoration(labelText: 'Nombre'),
              validator: _validar,
            ),
            TextFormField(
              controller: _cargo,
              decoration: const InputDecoration(labelText: 'Cargo'),
              validator: _validar,
            ),
            const SizedBox(height: 24),
            FilledButton(onPressed: _entrar, child: const Text('Ingresar')),
          ],
        ),
      ),
    );
  }
}
