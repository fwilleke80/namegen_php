# German Name Generator (PHP, Web)

This is a **PHP port** of the original Python *namegen* script.  
It generates random German-style names from syllable sets stored in a JSON file.  

The script is **web-only** — no CLI. Drop the two files into the same folder on your web server:

- `index.php` — the generator script (this is what you open in the browser).
- `namegen_data.json` — syllable data (firstnames, lastnames, nobility prefixes).

---

## Features

- **First names**: built from 2 or 3 syllables (with configurable probability of an extra middle syllable).
- **Last names**: built from 2 or 3 syllables, sometimes doubled with a hyphen.
- **Nobility prefixes**: occasionally prepended (e.g., *von*, *zu*).
- **Gender support**: male, female, or random.
- **Statistics**: computes total possible combinations and syllable counts.

All thresholds and probabilities mirror the Python version.

---

## Usage

1. Copy `index.php` and `namegen_data.json` into the same directory on your PHP-enabled server.
2. Open `index.php` in your browser.

You’ll see a form with the following options:

- **Gender**  
  `male`, `female`, or `random`
- **Count**  
  Number of names to generate
- **Name mode**  
  - Full name (default)  
  - Firstname only  
  - Lastname only
- **Show statistics**  
  Displays a breakdown of syllable counts and possible name combinations.

Click **Run** to see the results.

---

## Example

Generate 5 random male full names:

# German Name Generator (PHP, Web)

This is a **PHP port** of the original Python *namegen* script.  
It generates random German-style names from syllable sets stored in a JSON file.  

The script is **web-only** — no CLI. Drop the two files into the same folder on your web server:

- `index.php` — the generator script (this is what you open in the browser).
- `namegen_data.json` — syllable data (firstnames, lastnames, nobility prefixes).

---

## Features

- **First names**: built from 2 or 3 syllables (with configurable probability of an extra middle syllable).
- **Last names**: built from 2 or 3 syllables, sometimes doubled with a hyphen.
- **Nobility prefixes**: occasionally prepended (e.g., *von*, *zu*).
- **Gender support**: male, female, or random.
- **Statistics**: computes total possible combinations and syllable counts.

All thresholds and probabilities mirror the Python version.

---

## Usage

1. Copy `index.php` and `namegen_data.json` into the same directory on your PHP-enabled server.
2. Open `index.php` in your browser.

You’ll see a form with the following options:

- **Gender**  
  `male`, `female`, or `random`
- **Count**  
  Number of names to generate
- **Name mode**  
  - Full name (default)  
  - Firstname only  
  - Lastname only
- **Show statistics**  
  Displays a breakdown of syllable counts and possible name combinations.

Click **Run** to see the results.

---

## Example

Generate 5 random male full names:

1. Arhelm Grunstein
2. Belric Von Hohenbach
3. Orwald Kleinhammer
4. Dalfried Gerstorff
5. Fendric Langenfels

Show statistics:

### Firstnames:  
Female short names : 12,345  
Female long names : 67,890  
Female names in total : 80,235  
Male short names : 23,456  
Male long names : 78,901  
Male names in total : 102,357  
Firstnames in total : 182,592  

### Lastnames:
Short lastnames : 15,876  
Long lastnames : 200,345  
Lastnames in total : 216,221  

### Nobility titles:
Female nobility titles : 4  
Male nobility titles : 6  
Nobility titles total : 10  

### Total:
Female name combinations : 1,735,444,395  
Male name combinations : 2,213,331,267  
Name combinations in total: 3,948,775,662  


*(Numbers depend on your `namegen_data.json` content.)*

---

## Requirements

- PHP 8.0 or newer
- A web server with PHP enabled
- `mbstring` extension (for proper case conversion)

---

## Notes

- The probabilities for extra syllables, double last names, and nobility titles are hardcoded in the script to match the original Python behavior.
- To customize, edit the thresholds inside `NameGenerator` (e.g., `_threshDoubleLastName`).

---

## License

This project is released into the public domain under [The Unlicense](https://unlicense.org/).

You are free to copy, modify, distribute, and use the code and data for any purpose, commercial or non-commercial, without restriction.  

### Disclaimer
This software is provided "AS IS", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. In no event shall the authors be liable for any claim, damages, or other liability, whether in an action of contract, tort, or otherwise, arising from, out of, or in connection with the software or the use or other dealings in the software.