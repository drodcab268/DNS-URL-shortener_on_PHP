import dns.resolver
import webbrowser
import sys

DOMAIN = "drodcab.es"  # ✅ tu dominio

def get_long_url(short_code):
    full_domain = f"{short_code}.{DOMAIN}"

    try:
        answer = dns.resolver.resolve(full_domain, "TXT")
        for txt_record in answer:
            url = txt_record.to_text().strip('"')
            return url
    except Exception as e:
        print(f"Error obteniendo el registro DNS: {e}")
        return None


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Uso: python shortener.py <codigo>")
        sys.exit(1)

    short_code = sys.argv[1]
    url = get_long_url(short_code)

    if url:
        print(f"Redirigiendo a: {url}")
        webbrowser.open(url)
    else:
        print("No se encontró una URL asociada.")