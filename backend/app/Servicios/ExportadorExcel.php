<?php

namespace App\Servicios;

use App\Support\HoraLocal;
use BackedEnum;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Genera un .xlsx con OpenSpout en el momento (sin colas ni archivos guardados) y lo devuelve como descarga.
 * Lo usan los reportes y la lista de viajes. Las fechas se escriben en hora local de los usuarios.
 */
class ExportadorExcel
{
    public const TIPO = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

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
            $writer = new Writer;
            $writer->openToFile('php://output');
            $negrita = (new Style)->setFontBold();

            foreach (array_values($hojas) as $i => $hoja) {
                $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($hoja['nombre']);
                $writer->addRow(Row::fromValues($hoja['encabezados'], $negrita));
                foreach ($hoja['filas'] as $fila) {
                    $writer->addRow(Row::fromValues(array_map($this->valor(...), array_values($fila))));
                }
            }

            $writer->close();
        }, $nombreArchivo, ['Content-Type' => self::TIPO]);
    }

    private function valor(mixed $valor): mixed
    {
        return match (true) {
            $valor instanceof DateTimeInterface => HoraLocal::formatear($valor, 'd/m/Y H:i'),
            $valor instanceof BackedEnum => $valor->value,
            default => $valor,
        };
    }
}
