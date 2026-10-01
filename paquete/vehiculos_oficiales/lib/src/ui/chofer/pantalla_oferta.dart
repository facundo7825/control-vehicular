import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../avisos/avisos_viaje.dart';
import '../../chofer/cuenta_regresiva.dart';
import '../../chofer/turno.dart';
import '../../modelos/modelos.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Spec 7, chofer 3: oferta entrante a pantalla completa con la cuenta regresiva del servidor.
/// No se sale con "atrás": se acepta, se rechaza o vence. El timbre y la vibración los maneja [AvisosViaje].
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

  @override
  void initState() {
    super.initState();
    _oferta = ref.read(viajeActualProvider).value?.oferta;
  }

  void _vencer() {
    // Con una respuesta en vuelo no se vence: se espera a saber qué pasó (al terminar se reconstruye y, si
    // la oferta sigue ahí con la cuenta en cero, vence).
    if (_vencida || _respondiendo) return;
    setState(() => _vencida = true);
    ref.read(viajeActualProvider.notifier).ofertaVencida(_oferta!.id);
  }

  Future<void> _responder({required bool aceptar}) async {
    setState(() => _respondiendo = true);
    ref.read(avisosViajeProvider.notifier).silenciarOferta(_oferta!.id);
    final notifier = ref.read(viajeActualProvider.notifier);
    try {
      if (aceptar) {
        await notifier.aceptarOferta(); // con el viaje asignado, InicioChofer pasa a su pantalla
      } else {
        await notifier.rechazarOferta();
        if (mounted) _volver();
      }
    } on ErrorApi catch (e) {
      if (!mounted) return;
      mostrarError(context, e);
      if (e is ErrorNegocio) _volver(); // "La oferta ya no está vigente."
    } finally {
      if (mounted) setState(() => _respondiendo = false);
    }
  }

  /// Vuelve al mapa, o a otra oferta que haya llegado mientras tanto.
  void _volver() => context.go(ref.read(viajeActualProvider).value?.oferta != null ? Rutas.ofertaChofer : Rutas.chofer);

  @override
  Widget build(BuildContext context) {
    final seguimiento = ref.watch(viajeActualProvider).value;
    final actual = seguimiento?.oferta;
    if (actual != null && actual.id != _oferta?.id) {
      // Otra oferta llegó con esta pantalla abierta (la ruta se reutiliza): empieza de cero.
      _vencida = false;
    }
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
      if (r == Duration.zero) _vencer();
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
            child: _vencida
                ? _Vencida(alVolver: _volver)
                : _Detalle(oferta: oferta, restante: restante, alResponder: _botones),
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
  const _Vencida({required this.alVolver});

  final VoidCallback alVolver;

  @override
  Widget build(BuildContext context) => Column(
    mainAxisAlignment: MainAxisAlignment.center,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const Icon(Icons.timer_off, size: 48),
      const SizedBox(height: 16),
      Text('La oferta venció', textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 32),
      FilledButton(onPressed: alVolver, child: const Text('Volver al mapa')),
    ],
  );
}
