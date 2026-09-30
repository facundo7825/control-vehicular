/// Traduce los 401 de la API en **una sola** llamada a `onSesionInvalida` de la app principal
/// (spec 9), aunque fallen varios pedidos a la vez.
class AvisoSesionInvalida {
  AvisoSesionInvalida(this._callbackHost);

  final void Function() _callbackHost;
  final List<void Function()> _escuchas = [];
  bool _avisado = false;

  /// Mientras es `true`, un 401 no avisa (se usa al probar un token guardado).
  bool silenciado = false;

  bool get avisado => _avisado;

  void escuchar(void Function() escucha) => _escuchas.add(escucha);

  void avisar() {
    if (_avisado || silenciado) return;
    _avisado = true;
    for (final e in _escuchas) {
      e();
    }
    _callbackHost();
  }
}
