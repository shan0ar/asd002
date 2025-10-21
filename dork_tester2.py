#!/usr/bin/env python3
"""
Tester des dorks Google sur un site (pentwest.com) avec Selenium.
Affiche les résultats directement dans le terminal.
"""

import time
import random
import argparse
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.common.exceptions import TimeoutException
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from urllib.parse import quote_plus
from pathlib import Path

# ---------------------
# CONFIG
# ---------------------
DEFAULT_WORDLIST = ("https://raw.githubusercontent.com/"
                    "zebbern/DorkingWordlists/refs/heads/main/AutoDorks/File_Searches.txt")

DEFAULT_SITE = "pentwest.com"
DEFAULT_FILETYPES = ["pdf", "csv", "xml", "xlsx"]
MAX_EXAMPLES = 5
WAIT_TIMEOUT = 10  # secondes

# ---------------------
# FUNCTIONS
# ---------------------
def fetch_wordlist(source: str):
    import requests
    if source.startswith("http://") or source.startswith("https://"):
        r = requests.get(source)
        r.raise_for_status()
        lines = r.text.splitlines()
    else:
        p = Path(source)
        if not p.exists():
            raise FileNotFoundError(f"Wordlist file not found: {source}")
        lines = p.read_text(encoding="utf-8").splitlines()
    # nettoyage simple
    cleaned = []
    for l in lines:
        l = l.strip()
        if not l or l.startswith("#"):
            continue
        if "|" in l:
            parts = [p.strip() for p in l.split("|") if p.strip()]
            cleaned.extend(parts)
        else:
            cleaned.append(l)
    # dedupe
    seen = set()
    final = []
    for l in cleaned:
        if l not in seen:
            seen.add(l)
            final.append(l)
    return final

def normalize_dork(dork: str):
    d = dork.strip().strip('"').strip("'")
    if "|" in d:
        d = d.split("|",1)[0].strip()
    return d

def build_queries(dork: str, site: str, filetypes):
    queries = []
    if "site:" in dork.lower():
        import re
        d = re.sub(r"(?i)site:[^\s]+", f"site:{site}", dork)
        queries.append(d)
    else:
        queries.append(f"site:{site} {dork}")
    if "filetype:" not in dork.lower():
        for ft in filetypes:
            queries.append(f"site:{site} {dork} filetype:{ft}")
    return queries

def human_pause(base=2, jitter=2):
    s = base + random.random() * jitter
    time.sleep(s)

# ---------------------
# MAIN
# ---------------------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--wordlist", "-w", default=DEFAULT_WORDLIST, help="Wordlist URL ou chemin local")
    parser.add_argument("--site", "-s", default=DEFAULT_SITE, help="Site cible")
    parser.add_argument("--filetypes", "-f", default=",".join(DEFAULT_FILETYPES), help="Filetypes séparés par ,")
    parser.add_argument("--max", "-m", type=int, default=0, help="Nombre max de dorks (0=tous)")
    parser.add_argument("--headless", action="store_true", help="Activer Chrome headless")
    args = parser.parse_args()

    filetypes = [t.strip() for t in args.filetypes.split(",") if t.strip()]
    lines = fetch_wordlist(args.wordlist)

    # Setup Selenium Chrome
    chrome_options = Options()
    if args.headless:
        chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--disable-blink-features=AutomationControlled")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-gpu")
    chrome_options.add_argument("--disable-extensions")
    chrome_options.add_argument("--disable-dev-shm-usage")
    driver = webdriver.Chrome(options=chrome_options)

    total = 0
    try:
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
                print("------------------------------------------------------------")
                print(f"[{total}] QUERY: {q}")
                search_url = f"https://www.google.com/search?q={quote_plus(q)}&hl=en"

                try:
                    driver.get(search_url)
                    WebDriverWait(driver, WAIT_TIMEOUT).until(
                        EC.presence_of_all_elements_located((By.CSS_SELECTOR, "div#search a"))
                    )
                except TimeoutException:
                    print("RESULT: maybe (timeout / Google CAPTCHA?)")
                    continue

                # extraire les liens
                links = []
                anchors = driver.find_elements(By.CSS_SELECTOR, "div#search a")
                for a in anchors:
                    href = a.get_attribute("href")
                    if href and "google.com" not in href and href.startswith("http"):
                        if href not in links:
                            links.append(href)
                    if len(links) >= MAX_EXAMPLES:
                        break

                if links:
                    print("RESULT: yes")
                    for i, l in enumerate(links,1):
                        print(f"  [{i}] {l}")
                else:
                    print("RESULT: no")

                human_pause(base=1.5, jitter=2.5)
    finally:
        driver.quit()
    print("------------------------------------------------------------")
    print("Terminé.")

if __name__ == "__main__":
    main()
