<?php

namespace App\Servicios;

use App\Support\HoraLocal;
use BackedEnum;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Genera un .xlsx con OpenSpout en el momento (sin colas ni archivos guardados) y lo devuelve como descarga.
 * Lo usan los reportes y la lista de viajes. Las fechas se escriben en hora local de los usuarios.
 *
 * Inyección de fórmulas (OWASP "CSV Injection"): los textos vienen de la base (nombres, direcciones,
 * motivos) y nunca se escriben como fórmula. Cell::fromValue convierte en fórmula todo texto que empieza
 * con "=", así que acá los textos van siempre como celda de texto, y además a los que empiezan con
 * "=", "@", tabulación o retorno de carro se les antepone un apóstrofo, para que tampoco se interpreten si
 * alguien copia la celda o guarda el archivo como CSV. "+" y "-" no se tocan (teléfonos como "+54 11 ...",
 * textos con guion): la celda es de texto y Excel no la evalúa. Los números quedan numéricos.
 */
class ExportadorExcel
{
    public const TIPO = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const INICIOS_PELIGROSOS = ['=', '@', "\t", "\r"];

    /** @param  string|null  $carpetaTemporal  donde OpenSpout arma el archivo (por defecto la del sistema) */
    public function __construct(private ?string $carpetaTemporal = null) {}

    /**
     * Una sola hoja.
     *
     * @param  list<string>  $encabezados
     * @param  iterable<array<int|string, mixed>>  $filas
     */
    public function descargar(string $nombreArchivo, array $encabezados, iterable $filas, string $hoja = 'Hoja 1'): StreamedResponse
    {
        return $this->descargarHojas($nombreArchivo, [
            ['nombre' => $hoja, 'encabezados' => $encabezados, 'filas' => $filas],
        ]);
    }

    /**
     * Varias hojas, en orden.
     *
     * @param  list<array{nombre: string, encabezados: list<string>, filas: iterable<array<int|string, mixed>>}>  $hojas
     */
    public function descargarHojas(string $nombreArchivo, array $hojas): StreamedResponse
    {
        return response()->streamDownload(function () use ($hojas) {
            $opciones = new Options;
            if ($this->carpetaTemporal !== null) {
                $opciones->setTempFolder($this->carpetaTemporal);
            }
            $writer = new Writer($opciones);
            $writer->openToFile('php://output');

            try {
                $this->escribir($writer, $hojas);
            } catch (Throwable $e) {
                // Cerrar igual borra los archivos temporales de OpenSpout; el error original es el que importa.
                try {
                    $writer->close();
                } catch (Throwable) {
                }
                throw $e;
            }

            $writer->close();
        }, $nombreArchivo, ['Content-Type' => self::TIPO]);
    }

    private function escribir(Writer $writer, array $hojas): void
    {
        $negrita = (new Style)->setFontBold();

        foreach (array_values($hojas) as $i => $hoja) {
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($hoja['nombre']);
            $writer->addRow(new Row(array_map($this->celda(...), array_values($hoja['encabezados'])), $negrita));
            foreach ($hoja['filas'] as $fila) {
                $writer->addRow(new Row(array_map($this->celda(...), array_values($fila))));
            }
        }
    }

    private function celda(mixed $valor): Cell
    {
        $valor = match (true) {
            $valor instanceof DateTimeInterface => HoraLocal::formatear($valor, 'd/m/Y H:i'),
            $valor instanceof BackedEnum => $valor->value,
            default => $valor,
        };

        if (is_string($valor) && $valor !== '') {
            return new StringCell(self::neutralizar($valor), null);
        }

        return Cell::fromValue($valor);
    }

    /** Antepone un apóstrofo a los textos que una planilla podría tomar como fórmula. */
    public static function neutralizar(string $texto): string
    {
        return in_array($texto[0], self::INICIOS_PELIGROSOS, true) ? "'".$texto : $texto;
    }
}
