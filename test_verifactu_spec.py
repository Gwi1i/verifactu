"""
Tests de la implementación de referencia contra los vectores de prueba de la AEAT.

Fuente: "Detalle de las especificaciones técnicas para generación de la huella o
hash de los registros de facturación", AEAT v0.1.2 (27/08/2024), apartado 6.

Ejecutar:  python test_verifactu_spec.py
"""
import hashlib
import sys
from urllib.parse import urlencode, quote

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

CASO_1_EXPECTED = "3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60"
CASO_2_EXPECTED = "F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97"
CASO_3_EXPECTED = "177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68"

# URL de cotejo del QR (Orden HAC/1177/2024, capítulo VIII), entorno de producción.
QR_BASE_URL = "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR"


def compute_aeat_hash_alta(nif, num, fecha, tipo, cuota, total, huella_ant, timestamp):
    plain = (
        f"IDEmisorFactura={nif.strip()}&NumSerieFactura={num.strip()}"
        f"&FechaExpedicionFactura={fecha.strip()}&TipoFactura={tipo.strip()}"
        f"&CuotaTotal={cuota.strip()}&ImporteTotal={total.strip()}"
        f"&Huella={huella_ant.strip()}&FechaHoraHusoGenRegistro={timestamp.strip()}"
    )
    return plain, hashlib.sha256(plain.encode('utf-8')).hexdigest().upper()


def compute_aeat_hash_anulacion(nif_anulado, num_anulado, fecha_anulado, huella_ant, timestamp):
    plain = (
        f"IDEmisorFacturaAnulada={nif_anulado.strip()}&NumSerieFacturaAnulada={num_anulado.strip()}"
        f"&FechaExpedicionFacturaAnulada={fecha_anulado.strip()}&Huella={huella_ant.strip()}"
        f"&FechaHoraHusoGenRegistro={timestamp.strip()}"
    )
    return plain, hashlib.sha256(plain.encode('utf-8')).hexdigest().upper()


def qr_url(nif, numserie, fecha, importe):
    """Cuatro parámetros: nif, numserie, fecha (DD-MM-AAAA) e importe (punto decimal). Sin huella."""
    params = [("nif", nif), ("numserie", numserie), ("fecha", fecha), ("importe", f"{float(importe):.2f}")]
    return QR_BASE_URL + "?" + urlencode(params, quote_via=quote)


def validar_nif(nif):
    """Dígito de control de DNI, NIE, NIF K/L/M y CIF. Misma lógica que el plugin y el validador web."""
    import re
    nif = nif.strip().upper()
    if not re.fullmatch(r"[A-Z0-9]{9}", nif):
        return False
    letras = "TRWAGMYFPDXBNJZSQVHLCKE"
    if re.fullmatch(r"[0-9]{8}[A-Z]", nif):
        return nif[8] == letras[int(nif[:8]) % 23]
    if re.fullmatch(r"[XYZ][0-9]{7}[A-Z]", nif):
        return nif[8] == letras[int({"X": "0", "Y": "1", "Z": "2"}[nif[0]] + nif[1:8]) % 23]
    if re.fullmatch(r"[KLM][0-9]{7}[A-Z]", nif):
        return nif[8] == letras[int(nif[1:8]) % 23]
    if re.fullmatch(r"[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]", nif):
        suma = 0
        for i, ch in enumerate(nif[1:8]):
            d = int(ch)
            if i % 2 == 0:
                x = d * 2
                suma += x // 10 + x % 10
            else:
                suma += d
        control = (10 - suma % 10) % 10
        letra = "JABCDEFGHI"[control]
        tipo, c = nif[0], nif[8]
        if tipo in "PQRSNW":
            return c == letra
        if tipo in "ABEH":
            return c == str(control)
        return c == str(control) or c == letra
    return False


def run_official_aeat_test_suite():
    print("=" * 75)
    print("Vectores de prueba AEAT v0.1.2 (27/08/2024), apartado 6")
    print("=" * 75)

    plain1, h1 = compute_aeat_hash_alta("89890001K", "12345678/G33", "01-01-2024", "F1",
                                        "12.35", "123.45", "", "2024-01-01T19:20:30+01:00")
    print(f"\n[Caso 1: primer registro de alta]\n   {plain1}\n   {h1}")
    assert h1 == CASO_1_EXPECTED, f"Caso 1: {h1} != {CASO_1_EXPECTED}"
    print("   OK")

    plain2, h2 = compute_aeat_hash_alta("89890001K", "12345679/G34", "01-01-2024", "F1",
                                        "12.35", "123.45", h1, "2024-01-01T19:20:35+01:00")
    print(f"\n[Caso 2: alta encadenada]\n   {plain2}\n   {h2}")
    assert h2 == CASO_2_EXPECTED, f"Caso 2: {h2} != {CASO_2_EXPECTED}"
    print("   OK")

    plain3, h3 = compute_aeat_hash_anulacion("89890001K", "12345679/G34", "01-01-2024",
                                             h2, "2024-01-01T19:20:40+01:00")
    print(f"\n[Caso 3: anulación encadenada]\n   {plain3}\n   {h3}")
    assert h3 == CASO_3_EXPECTED, f"Caso 3: {h3} != {CASO_3_EXPECTED}"
    print("   OK")

    # El ejemplo de la especificación: los espacios al inicio y final se eliminan.
    _, h1b = compute_aeat_hash_alta("89890001K", "   12345678/G33  ", "01-01-2024", "F1",
                                    "12.35", "123.45", "", "2024-01-01T19:20:30+01:00")
    assert h1b == CASO_1_EXPECTED, "trim"
    print("\n[Trim de espacios]\n   OK")


def run_qr_tests():
    print("\n" + "=" * 75)
    print("URL del código QR (Orden HAC/1177/2024, capítulo VIII)")
    print("=" * 75)
    url = qr_url("89890001K", "12345678/G33", "01-01-2024", "123.45")
    esperado = ("https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR"
                "?nif=89890001K&numserie=12345678%2FG33&fecha=01-01-2024&importe=123.45")
    print(f"   {url}")
    assert url == esperado, url
    assert "hash=" not in url, "la huella no forma parte del QR"
    assert qr_url("B12345674", "F2026-000001", "18-09-2026", "10").endswith("importe=10.00")
    print("   OK")


def run_nif_tests():
    print("\n" + "=" * 75)
    print("Dígito de control de NIF / NIE / CIF")
    print("=" * 75)
    validos = ["89890001K", "12345678Z", "X1234567L", "Y1234567X", "Z1234567R",
               "B12345674", "A58818501", "P1234567D", "Q2826000H", "N0032484H"]
    invalidos = ["99999999Z", "12345678A", "B12345670", "X1234567A", "A5881850X",
                 "1234", "", "P12345674", "ABCDEFGHI"]
    for n in validos:
        assert validar_nif(n), f"debería ser válido: {n}"
    for n in invalidos:
        assert not validar_nif(n), f"debería ser inválido: {n}"
    print(f"   {len(validos)} válidos y {len(invalidos)} inválidos reconocidos correctamente")
    print("   OK")


if __name__ == '__main__':
    run_official_aeat_test_suite()
    run_qr_tests()
    run_nif_tests()
    print("\nTodos los tests han pasado.")
