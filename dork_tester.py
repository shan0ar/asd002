#!/usr/bin/env python3
"""
google_dork_tester.py
Teste des dorks (wordlist) contre Google pour site:pentwest.com + filetypes (pdf,csv,xml,xlsx).
Sortie brute dans le terminal (no CSV).
Usage: python3 google_dork_tester.py [--wordlist URL_OR_PATH] [--site SITE] [--filetypes pdf,csv]
"""
import argparse
import requests
import time
import random
import sys
from bs4 import BeautifulSoup
from urllib.parse import quote_plus
from pathlib import Path

DEFAULT_WORDLIST = ("https://raw.githubusercontent.com/"
                    "zebbern/DorkingWordlists/refs/heads/main/AutoDorks/File_Searches.txt")

HEADERS = {
    "User-Agent": ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                   "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36"),
    "Accept-Language": "en-US,en;q=0.9"
}

SESSION = requests.Session()
SESSION.headers.update(HEADERS)
SESSION.max_redirects = 5
TIMEOUT = 15

def fetch_wordlist(source: str):
    if source.startswith("http://") or source.startswith("https://"):
        r = SESSION.get(source, timeout=TIMEOUT)
        r.raise_for_status()
        content = r.text
    else:
        p = Path(source)
        if not p.exists():
            print(f"[ERROR] Wordlist file not found: {source}", file=sys.stderr)
            sys.exit(2)
        content = p.read_text(encoding="utf-8", errors="ignore")
    lines = []
    for raw in content.splitlines():
        s = raw.strip()
        if not s or s.startswith("#"):
            continue
        # split pipe-delimited entries common in some lists
        if "|" in s:
            parts = [p.strip() for p in s.split("|") if p.strip()]
            lines.extend(parts)
        else:
            lines.append(s)
    # dedupe, preserve order
    seen = set()
    cleaned = []
    for l in lines:
        if l in seen:
            continue
        seen.add(l)
        cleaned.append(l)
    return cleaned

def normalize_dork(d: str):
    # minimal cleanup: strip quotes and trailing noise
    d = d.strip().strip('"').strip("'")
    # remove comments after a pipe
    if "|" in d:
        d = d.split("|",1)[0].strip()
    return d

def build_queries(dork: str, site: str, filetypes):
    qlist = []
    d = dork
    if "site:" in dork.lower():
        # replace any site:xxx with our site
        import re
        d = re.sub(r"(?i)site:[^\s]+", f"site:{site}", dork)
        qlist.append(d)
    else:
        qlist.append(f"site:{site} {d}")
    # if dork already includes filetype:, don't add duplicates
    if "filetype:" not in dork.lower():
        for ft in filetypes:
            qlist.append(f"site:{site} {d} filetype:{ft}")
    return qlist

def google_search(query: str):
    """Renvoie (status, html). status: 'ok','no_results','blocked','error'"""
    params = {"q": query, "hl": "en"}
    try:
        r = SESSION.get("https://www.google.com/search", params=params, timeout=TIMEOUT)
    except Exception as e:
        return ("error", f"request-error: {e}")
    html = r.text
    # detect block/captcha
    if ("Our systems have detected unusual traffic" in html
        or "To continue, please type the characters below" in html
        or r'<title>403 Forbidden</title>' in html):
        return ("blocked", html)
    # quick no-results detection (varies by locale)
    if ("did not match any documents" in html
        or "No results found for" in html
        or "Aucun résultat" in html):
        return ("no_results", html)
    # otherwise ok
    return ("ok", html)

def extract_links_from_google_html(html: str, max_links=5):
    soup = BeautifulSoup(html, "html.parser")
    links = []
    # Google often wraps target links in /url?q=<realurl>&...
    for a in soup.find_all("a", href=True):
        href = a['href']
        if href.startswith("/url?q="):
            # extract after /url?q=
            try:
                real = href.split("/url?q=",1)[1].split("&",1)[0]
            except Exception:
                continue
            if real not in links:
                links.append(real)
        elif href.startswith("http") and "google" not in href:
            if href not in links:
                links.append(href)
        if len(links) >= max_links:
            break
    return links

def human_pause(base=2, jitter=3):
    s = base + random.random()*jitter
    time.sleep(s)

def main():
    parser = argparse.ArgumentParser(description="Tester des dorks Google (sortie brute).")
    parser.add_argument("--wordlist", "-w", default=DEFAULT_WORDLIST,
                        help="URL ou chemin local vers la wordlist")
    parser.add_argument("--site", "-s", default="lacoste.com", help="Site cible (default lacoste.com)")
    parser.add_argument("--filetypes", "-f", default="pdf,csv,xml,xlsx",
                        help="Liste de filetypes séparés par ,")
    parser.add_argument("--max", "-m", type=int, default=0,
                        help="Max de dorks (0 = tous)")
    parser.add_argument("--examples", "-e", type=int, default=5,
                        help="Nombre d'exemples à afficher par query")
    parser.add_argument("--no-sleep", action="store_true", help="Désactive les pauses entre requêtes (risque de block)")
    args = parser.parse_args()

    try:
        lines = fetch_wordlist(args.wordlist)
    except Exception as exc:
        print(f"[ERROR] Impossible de récupérer la wordlist: {exc}", file=sys.stderr)
        sys.exit(1)

    filetypes = [t.strip() for t in args.filetypes.split(",") if t.strip()]
    total = 0
    for raw in lines:
        if args.max and total >= args.max:
            break
        dork = normalize_dork(raw)
        if len(dork) < 2:
            continue
        queries = build_queries(dork, args.site, filetypes)
        for q in queries:
            total += 1
            if args.max and total > args.max:
                break
            # print header line brut
            print("------------------------------------------------------------")
            print(f"[{total}] QUERY: {q}")
            status, html_or_msg = google_search(q)
            if status == "blocked":
                print("[BLOCKED] Google a probablement bloqué la requête (captcha ou 403).")
                print("         Essaie avec l'API Custom Search, un proxy résidentiel, ou réduis le rythme.")
                # show small hint snippet to debug
                snippet = html_or_msg[:800].replace("\n", " ")
                print("         (extrait page):", snippet)
                # still continue but mark as maybe
                print("RESULT: maybe")
            elif status == "no_results":
                print("RESULT: no")
            elif status == "error":
                print("RESULT: error ->", html_or_msg)
            else:
                links = extract_links_from_google_html(html_or_msg, max_links=args.examples)
                if links:
                    print("RESULT: yes")
                    for n,u in enumerate(links,1):
                        print(f"  [{n}] {u}")
                else:
                    # sometimes structure differs: mark as maybe
                    print("RESULT: no (no links extracted)")

            # pause
            if not args.no_sleep:
                human_pause(base=1.5, jitter=2.5)
    print("------------------------------------------------------------")
    print("Terminé.")

if __name__ == "__main__":
    main()
