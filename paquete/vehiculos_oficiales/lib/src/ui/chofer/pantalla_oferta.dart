import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/cuenta_regresiva.dart';
import '../../chofer/turno.dart';
import '../../modelos/modelos.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Vibración y sonido de la oferta. Costura para los tests. Nunca lanza: sin vibrador o sin
/// sonido del sistema (web, tests) no pasa nada.
final alertaOfertaProvider = Provider<void Function()>(
  (ref) =>
      () => unawaited(_alertar()),
);

Future<void> _alertar() async {
  try {
    await HapticFeedback.vibrate();
    await SystemSound.play(SystemSoundType.alert);
  } catch (_) {
    // Plataforma sin vibración o sin sonidos del sistema.
  }
}

/// Spec 7, chofer 3: oferta entrante a pantalla completa con la cuenta regresiva del servidor.
/// No se sale con "atrás": se acepta, se rechaza o vence.
class PantallaOferta extends ConsumerStatefulWidget {
  const PantallaOferta({super.key});

  @override
  ConsumerState<PantallaOferta> createState() => _PantallaOfertaState();
}

class _PantallaOfertaState extends ConsumerState<PantallaOferta> {
  /// La oferta que se está mostrando (queda al vencer, cuando ya no está en el estado).
  Oferta? _oferta;
  bool _vencida = false;
  bool _respondiendo = false;
  int _segundos = 0;

  @override
  void initState() {
    super.initState();
    _oferta = ref.read(viajeActualProvider).value?.oferta;
    ref.read(alertaOfertaProvider)();
  }

  void _vencer() {
    if (_vencida) return;
    setState(() => _vencida = true);
    ref.read(viajeActualProvider.notifier).ofertaVencida(_oferta!.id);
  }

  Future<void> _responder({required bool aceptar}) async {
    setState(() => _respondiendo = true);
    final notifier = ref.read(viajeActualProvider.notifier);
    try {
      if (aceptar) {
        await notifier.aceptarOferta(); // con el viaje asignado, InicioChofer pasa a su pantalla
      } else {
        await notifier.rechazarOferta();
        if (mounted) context.go(Rutas.chofer);
      }
    } on ErrorApi catch (e) {
      if (!mounted) return;
      mostrarError(context, e);
      if (e is ErrorNegocio) context.go(Rutas.chofer); // "La oferta ya no está vigente."
    } finally {
      if (mounted) setState(() => _respondiendo = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final seguimiento = ref.watch(viajeActualProvider).value;
    final actual = seguimiento?.oferta;
    if (actual != null) _oferta = actual;
    final oferta = _oferta;

    // La oferta se fue sin que la respondiera acá (la canceló el solicitante, la tomó otro): se vuelve.
    if (oferta == null || (actual == null && !_vencida && !_respondiendo)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        context.go(ref.read(viajeActualProvider).value?.viaje != null ? Rutas.viajeChofer : Rutas.chofer);
      });
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final restante = ref.watch(restanteProvider(oferta.venceEn));
    ref.listen(restanteProvider(oferta.venceEn), (_, r) {
      if (r == Duration.zero) return _vencer();
      if (++_segundos % 5 == 0) ref.read(alertaOfertaProvider)(); // cada 5 s mientras está abierta
    });
    if (restante == Duration.zero && !_vencida) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _vencer();
      });
    }

    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: _vencida ? const _Vencida() : _Detalle(oferta: oferta, restante: restante, alResponder: _botones),
          ),
        ),
      ),
    );
  }

  Widget _botones() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      FilledButton(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
        onPressed: _respondiendo ? null : () => _responder(aceptar: true),
        child: const Text('Aceptar'),
      ),
      const SizedBox(height: 12),
      OutlinedButton(onPressed: _respondiendo ? null : () => _responder(aceptar: false), child: const Text('Rechazar')),
    ],
  );
}

class _Detalle extends ConsumerWidget {
  const _Detalle({required this.oferta, required this.restante, required this.alResponder});

  final Oferta oferta;
  final Duration restante;
  final Widget Function() alResponder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final viaje = oferta.viaje;
    final texto = Theme.of(context).textTheme;
    final aqui = ref.watch(posicionPropiaProvider).punto?.posicion;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Nuevo viaje', style: texto.headlineMedium, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        Text('${segundosRestantes(restante)} s', style: texto.displayMedium, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        Text(viaje.solicitante.nombre, style: texto.titleLarge),
        if (aqui != null) Text('A ${formatearDistancia(distanciaMetros(aqui, viaje.origen.coordenada))} del origen'),
        const SizedBox(height: 8),
        Text('Origen: ${viaje.origen.descripcion}'),
        Text('Destino: ${viaje.destino.descripcion}'),
        if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
        const Spacer(),
        alResponder(),
      ],
    );
  }
}

class _Vencida extends StatelessWidget {
  const _Vencida();

  @override
  Widget build(BuildContext context) => Column(
    mainAxisAlignment: MainAxisAlignment.center,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const Icon(Icons.timer_off, size: 48),
      const SizedBox(height: 16),
      Text('La oferta venció', textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 32),
      FilledButton(onPressed: () => context.go(Rutas.chofer), child: const Text('Volver al mapa')),
    ],
  );
}
