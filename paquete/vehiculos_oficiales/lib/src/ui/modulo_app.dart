import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:go_router/go_router.dart';

import '../entorno.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import 'chofer/inicio_chofer.dart';
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';
import 'solicitante/mis_viajes.dart';
import 'solicitante/pantalla_reserva.dart';
import 'solicitante/pantalla_viaje.dart';

/// Cierra el módulo y vuelve a la app principal. Lo define [ModuloVehiculos].
final cerrarModuloProvider = Provider<VoidCallback>((ref) => () {});

abstract final class Rutas {
  static const inicio = '/';
  static const solicitante = '/solicitante';
  static const viaje = '/solicitante/viaje';
  static const reservar = '/solicitante/reservar';
  static const misViajes = '/solicitante/mis-viajes';
  static const chofer = '/chofer';
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
class ModuloVehiculos extends StatelessWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: [
        entornoProvider.overrideWithValue(entorno),
        cerrarModuloProvider.overrideWithValue(alCerrar),
        ...overrides,
      ],
      child: _RaizModulo(tema: Theme.of(context)),
    );
  }
}

class _RaizModulo extends ConsumerStatefulWidget {
  const _RaizModulo({required this.tema});

  final ThemeData tema;

  @override
  ConsumerState<_RaizModulo> createState() => _RaizModuloState();
}

class _RaizModuloState extends ConsumerState<_RaizModulo> {
  final _cambiosDeSesion = ValueNotifier<int>(0);
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    ref.listenManual(sesionProvider, (_, _) => _cambiosDeSesion.value++);
    _router = GoRouter(
      refreshListenable: _cambiosDeSesion,
      redirect: (context, estado) {
        final sesion = ref.read(sesionProvider);
        final enInicio = estado.matchedLocation == Rutas.inicio;
        if (sesion is! SesionLista) return enInicio ? null : Rutas.inicio;
        if (enInicio) return sesion.usuario.esChofer ? Rutas.chofer : Rutas.solicitante;
        return null;
      },
      routes: [
        GoRoute(path: Rutas.inicio, builder: (_, _) => const PantallaInicio()),
        ShellRoute(
          builder: (_, _, child) => _ConSesion(child: child),
          routes: [
            GoRoute(
              path: Rutas.solicitante,
              builder: (_, _) => const InicioSolicitante(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const PantallaViaje()),
                GoRoute(
                  path: 'reservar',
                  builder: (_, estado) => PantallaReserva(fechaInicial: estado.extra as DateTime?),
                ),
                GoRoute(path: 'mis-viajes', builder: (_, _) => const MisViajesPantalla()),
              ],
            ),
            GoRoute(path: Rutas.chofer, builder: (_, _) => const InicioChofer()),
          ],
        ),
      ],
    );
  }

  @override
  void dispose() {
    _router.dispose();
    _cambiosDeSesion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // El "atrás" del sistema lo recibe primero el Navigator de la app principal, que cerraría todo el
    // módulo. Se bloquea ese pop y se le pasa al router interno (diálogos, hojas y rutas del módulo);
    // solo si ya no hay nada que cerrar adentro, se cierra el módulo.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) async {
        if (yaCerro) return;
        if (!await _router.routerDelegate.popRoute()) ref.read(cerrarModuloProvider)();
      },
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        title: 'Vehículos oficiales',
        theme: widget.tema,
        locale: const Locale('es', 'AR'),
        supportedLocales: const [Locale('es', 'AR'), Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: _router,
      ),
    );
  }
}

/// Pantallas con sesión lista: activa el puente push (registro del token y avisos).
class _ConSesion extends ConsumerStatefulWidget {
  const _ConSesion({required this.child});

  final Widget child;

  @override
  ConsumerState<_ConSesion> createState() => _ConSesionState();
}

class _ConSesionState extends ConsumerState<_ConSesion> {
  @override
  void initState() {
    super.initState();
    ref.read(pushModuloProvider);
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
