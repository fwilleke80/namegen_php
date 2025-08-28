<?php
declare(strict_types=1);

ini_set('display_errors', '0');  // don’t leak stack traces
ini_set('log_errors', '1');
@set_time_limit(5);                    // short runtime
@ini_set('memory_limit', '64M'); // small memory cap is fine for this

header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

/// @brief	German Name Generator (PHP port, web-only) with tunable sliders
/// @details	Loads namegen_data.json from the same folder and renders a form to generate names or show stats.
/// @author	Punga

// --------------------------------------------------------------------------------------
// Config / Metadata
// --------------------------------------------------------------------------------------

/** @var string */
const SCRIPTTITLE = 'German Name Generator';
/** @var string */
const SCRIPTVERSION = '1.9.0';
/** @var string */
const DATAFILENAME = 'namegen_data.json';

/** @var float Default thresholds (match your current code) */
const DEF_THRESH_FIRST_EXTRA = 0.28;
const DEF_THRESH_DOUBLE_LAST = 0.18;
const DEF_THRESH_LONGER_LAST = 0.28;
const DEF_THRESH_NOBILITY    = 0.20;

/** @var int Default last name syllable range (Python: randrange(2,4) → min=2, maxExclusive=4) */
const DEF_MIN_LASTNAME_SYLL  = 2;
const DEF_MAX_LASTNAME_SYLLX = 4;	// exclusive upper bound

const DEF_COUNT = 10;

// --------------------------------------------------------------------------------------
// Utilities
// --------------------------------------------------------------------------------------

/**
 * @brief	Random float in [0,1).
 * @return	float
 */
function frand(): float
{
	return mt_rand() / (mt_getrandmax() + 1);
}

/**
 * @brief	Safe array_get with default.
 * @param[in] arr
 * @param[in] key
 * @param[in] default
 * @return	mixed
 */
function array_get(array $arr, string $key, mixed $default = null): mixed
{
	return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

/**
 * @brief	Title-case helper (UTF-8).
 * @param[in] s
 * @return	string
 */
function titlecase(string $s): string
{
	return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

// --------------------------------------------------------------------------------------
// Name Generator
// --------------------------------------------------------------------------------------

/**
 * @brief	Name generator core class with runtime-tunable parameters.
 */
final class NameGenerator
{
	// Thresholds (same semantics as the Python version): probabilities to ADD something if frand() < threshold
	private float $_threshExtraFirstnameSyllable = DEF_THRESH_FIRST_EXTRA;
	private float $_threshDoubleLastName = DEF_THRESH_DOUBLE_LAST;
	private float $_threshLongerLastName = DEF_THRESH_LONGER_LAST;
	private float $_threshNobility = DEF_THRESH_NOBILITY;

	// Limits / Ranges
	private int $_minLastnameSyllables = DEF_MIN_LASTNAME_SYLL;
	private int $_maxLastnameSyllables = DEF_MAX_LASTNAME_SYLLX; // exclusive upper bound

	// Data
	/** @var array<string, array{0: string[], 1: string[], 2: string[]}> */
	private array $_firstNameSyllables = [];
	/** @var string[] */
	private array $_lastNameSyllables = [];
	/** @var array<string, string[]> */
	private array $_nobilityPrefixes = [];

	/**
	 * @brief	Load JSON data file.
	 * @param[in] filePath Absolute or relative path.
	 * @return	bool True on success.
	 */
	public function loadData(string $filePath): bool
	{
		if (!is_file($filePath))
		{
			return false;
		}
		$json = file_get_contents($filePath);
		if ($json === false)
		{
			return false;
		}
		$data = json_decode($json, true);
		if (!is_array($data))
		{
			return false;
		}

		$this->_firstNameSyllables = (array)array_get($data, 'firstNameSyllables', []);
		$this->_lastNameSyllables = (array)array_get($data, 'lastNameSyllables', []);
		$this->_nobilityPrefixes = (array)array_get($data, 'nobilityPrefixes', []);

		// Minimal validation
		if (!$this->hasGender('male') || !$this->hasGender('female') || count($this->_lastNameSyllables) === 0)
		{
			return false;
		}
		return true;
	}

	/**
	 * @brief	Check if gender is present in data.
	 * @param[in] gender
	 * @return	bool
	 */
	private function hasGender(string $gender): bool
	{
		if (!array_key_exists($gender, $this->_firstNameSyllables))
		{
			return false;
		}
		$g = $this->_firstNameSyllables[$gender];
		return is_array($g) && isset($g[0], $g[1], $g[2]) && is_array($g[0]) && is_array($g[1]) && is_array($g[2]);
	}

	/**
	 * @brief	Override thresholds at runtime (values will be clamped).
	 * @param[in] firstExtra Threshold for extra firstname syllable
	 * @param[in] doubleLast Threshold for hyphenated double last name
	 * @param[in] longerLast Threshold for using 2–(max-1) syllables instead of 2
	 * @param[in] nobility   Threshold for adding a nobility prefix
	 * @return	void
	 */
	public function setThresholds(float $firstExtra, float $doubleLast, float $longerLast, float $nobility): void
	{
		$this->_threshExtraFirstnameSyllable = max(0.0, min(1.0, $firstExtra));
		$this->_threshDoubleLastName = max(0.0, min(1.0, $doubleLast));
		$this->_threshLongerLastName = max(0.0, min(1.0, $longerLast));
		$this->_threshNobility = max(0.0, min(1.0, $nobility));
	}

	/**
	 * @brief	Set last name syllable range (max is exclusive; clamps and normalizes).
	 * @param[in] minIncl Minimum syllables (inclusive)
	 * @param[in] maxExcl Maximum syllables (exclusive)
	 * @return	void
	 */
	public function setLastnameSyllableRange(int $minIncl, int $maxExcl): void
	{
		$min = max(1, $minIncl);
		$max = max($min + 1, $maxExcl); // ensure at least one valid integer in [min, maxExcl)
		$this->_minLastnameSyllables = $min;
		$this->_maxLastnameSyllables = $max;
	}

	/**
	 * @brief	Compute stats like the Python script.
	 * @return	array<string, mixed>
	 */
	public function computeStats(): array
	{
		$male1 = count($this->_firstNameSyllables['male'][0]);
		$male2 = count($this->_firstNameSyllables['male'][1]);
		$male3 = count($this->_firstNameSyllables['male'][2]);

		$female1 = count($this->_firstNameSyllables['female'][0]);
		$female2 = count($this->_firstNameSyllables['female'][1]);
		$female3 = count($this->_firstNameSyllables['female'][2]);

		$lastSyll = count($this->_lastNameSyllables);

		$male_short   = $male1 * $male3;
		$male_long    = $male1 * $male2 * $male3;
		$male_total   = $male_short + $male_long;

		$female_short = $female1 * $female3;
		$female_long  = $female1 * $female2 * $female3;
		$female_total = $female_short + $female_long;

		$first_total  = $male_total + $female_total;

		// 2 syllables vs 3..(max-1) syllables; approximate like the Python layout
		$last_short = $lastSyll ** 2;
		$last_long  = $lastSyll ** 3;
		$last_total = $last_short + $last_long;

		$male_nobl   = count(array_get($this->_nobilityPrefixes, 'male', []));
		$female_nobl = count(array_get($this->_nobilityPrefixes, 'female', []));
		$nobl_total  = $male_nobl + $female_nobl;

		$male_names   = $male_total   * $last_total * ($male_nobl + 1);
		$female_names = $female_total * $last_total * ($female_nobl + 1);
		$names_total  = $male_names + $female_names;

		return [
			'syllables'  => [
				'male1' => $male1, 'male2' => $male2, 'male3' => $male3,
				'female1' => $female1, 'female2' => $female2, 'female3' => $female3,
				'lastname' => $lastSyll
			],
			'firstnames' => [
				'male'   => ['short' => $male_short,   'long' => $male_long,   'total' => $male_total],
				'female' => ['short' => $female_short, 'long' => $female_long, 'total' => $female_total],
				'total'  => $first_total
			],
			'lastnames'  => [
				'short' => $last_short, 'long' => $last_long, 'total' => $last_total
			],
			'nobility'   => [
				'male' => $male_nobl, 'female' => $female_nobl, 'total' => $nobl_total
			],
			'male'       => $male_names,
			'female'     => $female_names,
			'total'      => $names_total
		];
	}

	/**
	 * @brief	Print stats in a human-friendly way (mirrors Python).
	 * @param[in] stats
	 * @return	void
	 */
	public function printStatistics(array $stats): void
	{
		echo "Firstnames:\n";
		echo "-----------\n";
		printf("Female short names     : %8s\n", number_format($stats['firstnames']['female']['short']));
		printf("Female long names      : %8s\n", number_format($stats['firstnames']['female']['long']));
		printf("Female names in total  : %8s\n", number_format($stats['firstnames']['female']['total']));
		echo "\n";
		printf("Male short names       : %8s\n", number_format($stats['firstnames']['male']['short']));
		printf("Male long names        : %8s\n", number_format($stats['firstnames']['male']['long']));
		printf("Male names in total    : %8s\n", number_format($stats['firstnames']['male']['total']));
		echo "\n";
		printf("Firstnames in total    : %8s\n", number_format($stats['firstnames']['total']));
		echo "\n";
		echo "Lastnames:\n";
		echo "----------\n";
		printf("Short lastnames        : %8s\n", number_format($stats['lastnames']['short']));
		printf("Long lastnames         : %8s\n", number_format($stats['lastnames']['long']));
		printf("Lastnames in total     : %8s\n", number_format($stats['lastnames']['total']));
		echo "\n";
		echo "Nobility titles:\n";
		echo "----------------\n";
		printf("Female nobility titles : %8s\n", number_format($stats['nobility']['female']));
		printf("Male nobility titles   : %8s\n", number_format($stats['nobility']['male']));
		printf("Nobility titles total  : %8s\n", number_format($stats['nobility']['total']));
		echo "\n";
		echo "Total:\n";
		echo "------------\n";
		printf("Female name combinations  : %15s\n", number_format($stats['female']));
		printf("Male name combinations    : %15s\n", number_format($stats['male']));
		printf("Name combinations in total: %15s\n", number_format($stats['total']));
	}

	/**
	 * @brief	Generate a random firstname for gender.
	 * @param[in] gender 'male'|'female'
	 * @return	string
	 */
	public function generateFirstname(string $gender = 'male'): string
	{
		$parts = $this->_firstNameSyllables[$gender];
		$name = $parts[0][array_rand($parts[0])];
		if (frand() < $this->_threshExtraFirstnameSyllable)
		{
			$name .= $parts[1][array_rand($parts[1])];
		}
		$name .= $parts[2][array_rand($parts[2])];
		return titlecase($name);
	}

	/**
	 * @brief	Generate a random lastname.
	 * @return	string
	 */
	public function generateLastname(): string
	{
		$syllables = (frand() < $this->_threshLongerLastName)
			? random_int($this->_minLastnameSyllables, $this->_maxLastnameSyllables - 1)
			: $this->_minLastnameSyllables;

		$name = '';
		$lastIdx = -1;

		for ($i = 0; $i < $syllables; ++$i)
		{
			do
			{
				$idx = random_int(0, count($this->_lastNameSyllables) - 1);
			}
			while ($idx === $lastIdx);

			$name .= $this->_lastNameSyllables[$idx];
			$lastIdx = $idx;
		}

		return titlecase($name);
	}

	/**
	 * @brief	Get a random nobility prefix for gender.
	 * @param[in] gender
	 * @return	string
	 */
	public function getNobilityPrefix(string $gender = 'male'): string
	{
		$list = array_get($this->_nobilityPrefixes, $gender, []);
		if (empty($list))
		{
			return '';
		}
		return $list[array_rand($list)];
	}

	/**
	 * @brief	Normalize gender and handle 'random'.
	 * @param[in] gender 'male'|'female'|'random'|'m'|'f'|'r'
	 * @return	string 'male'|'female'
	 */
	public function safeGender(string $gender): string
	{
		$g = strtolower($gender);
		if ($g === 'f') { $g = 'female'; }
		elseif ($g === 'm') { $g = 'male'; }
		elseif ($g === 'r') { $g = 'random'; }

		if ($g === 'random' || !array_key_exists($g, $this->_firstNameSyllables))
		{
			$keys = array_keys($this->_firstNameSyllables);
			$g = $keys[array_rand($keys)];
		}
		return $g;
	}

	/**
	 * @brief	Generate a full name or partial based on mode.
	 * @param[in] gender 'male'|'female'|'random'|'m'|'f'|'r'
	 * @param[in] mode   0=full, 1=firstname only, 2=lastname only
	 * @return	string
	 */
	public function generate(string $gender, int $mode = 0): string
	{
		$g = $this->safeGender($gender);

		if ($mode === 1)
		{
			return $this->generateFirstname($g);
		}

		$last = $this->generateLastname();
		if (frand() < $this->_threshDoubleLastName)
		{
			$last .= '-' . $this->generateLastname();
		}
		if (frand() < $this->_threshNobility)
		{
			$prefix = $this->getNobilityPrefix($g);
			if ($prefix !== '')
			{
				$last = $prefix . ' ' . $last;
			}
		}

		if ($mode === 2)
		{
			return $last;
		}

		$first = $this->generateFirstname($g);
		return $first . ' ' . $last;
	}
} // class NameGenerator

// --------------------------------------------------------------------------------------
// Web Controller (only)
// --------------------------------------------------------------------------------------

/**
 * @brief	Read a float GET param in [0,1], fall back to default.
 * @param[in] key
 * @param[in] def
 * @return	float
 */
function get01(string $key, float $def): float
{
	if (!isset($_GET[$key]))
	{
		return $def;
	}
	$v = (float)$_GET[$key];
	if (!is_finite($v))
	{
		return $def;
	}
	return max(0.0, min(1.0, $v));
}

/**
 * @brief	Read an int GET param within a range, fall back to default.
 * @param[in] key
 * @param[in] def
 * @param[in] min
 * @param[in] max
 * @return	int
 */
function getInt(string $key, int $def, int $min, int $max): int
{
	if (!isset($_GET[$key]))
	{
		return $def;
	}
	$v = (int)$_GET[$key];
	$v = max($min, min($max, $v));
	return $v;
}

$gender = isset($_GET['gender']) ? (string)$_GET['gender'] : 'random';
$count = getInt('count', DEF_COUNT, 1, 999);
$modeStr = isset($_GET['mode']) ? (string)$_GET['mode'] : '';
$stats = isset($_GET['stats']);

$mode = 0;
if ($modeStr === 'firstname') { $mode = 1; }
elseif ($modeStr === 'lastname') { $mode = 2; }

// Tunables from UI (sliders)
$t_first_extra = get01('t_first_extra', DEF_THRESH_FIRST_EXTRA);
$t_double_last = get01('t_double_last', DEF_THRESH_DOUBLE_LAST);
$t_longer_last = get01('t_longer_last', DEF_THRESH_LONGER_LAST);
$t_nobility    = get01('t_nobility',    DEF_THRESH_NOBILITY);

// Practical range for last name syllables (exclusive upper bound must be > min)
$min_last = getInt('min_last', DEF_MIN_LASTNAME_SYLL, 1, 8);
$max_last = getInt('max_last', DEF_MAX_LASTNAME_SYLLX, 2, 10);
if ($max_last <= $min_last)
{
	$max_last = $min_last + 1;
}

$gen = new NameGenerator();
$dataFile = __DIR__ . DIRECTORY_SEPARATOR . DATAFILENAME;
$loaded = $gen->loadData($dataFile);

$gen->setThresholds($t_first_extra, $t_double_last, $t_longer_last, $t_nobility);
$gen->setLastnameSyllableRange($min_last, $max_last);

mt_srand((int)microtime(true));
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars(SCRIPTTITLE . ' ' . SCRIPTVERSION, ENT_QUOTES) ?></title>
	<style>
		/* Standard entities */
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
		fieldset { padding: 1rem; border-radius: 8px; }
		label { display: block; margin: 0.5rem 0 0.25rem; }
		input[type="number"] { width: 7rem; }
		pre { background: #111; color: #0f0; padding: 1rem; border-radius: 8px; overflow: auto; }
		button { padding: .6rem 1rem; border-radius: 10px; border: 1px solid #ccc; background: #f6f6f6; cursor: pointer; -webkit-appearance: none; appearance: none; -webkit-text-fill-color: #111; color: #111; }
		button:hover { background: #eee; }
		button.save { padding: 0 .5rem; border-radius: 8px; border: 1px solid #ccc; background: #f6f6f6; cursor: pointer; -webkit-appearance: none; appearance: none; -webkit-text-fill-color: #111; color: #111; }
		button.save.saved::after { content: ' Saved'; font-size: .85em; color: #3a7; margin-left: .25rem; }
		/* Custom classes */
		.err { background: #fee; color: #900; padding: 0.75rem; border: 1px solid #f99; border-radius: 8px; }
		.grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap: 1rem; }
		.grid-2 { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 1rem; }
		.range-row { display: grid; grid-template-columns: 1fr 70px; align-items: center; gap: .75rem; }
		.range-row output { text-align: right; font-variant-numeric: tabular-nums; }
		.small { color: #666; font-size: .9rem; }
		hr { border: none; height: 1px; background: #ddd; margin: 1rem 0; }
		/* Collapsible parameters */
		details.params { border: 1px solid #ddd; border-radius: 8px; padding: .5rem .75rem; background: #fafafa; margin-top: 1rem; margin-bottom: 1rem; }
		details.params > summary { cursor: pointer; user-select: none; display: flex; align-items: center; gap: .5rem; font-weight: 600; outline: none; list-style: none;}
		details.params > summary::-webkit-details-marker { display: none; }
		details.params > summary::before { content: '▸'; transition: transform .15s ease-in-out; }
		details.params[open] > summary::before { transform: rotate(90deg); }
		details.params .content { margin-top: .75rem; }
		/* Result list */
		.results { list-style: none; padding: 0; margin: 0; }
		.results li { display: flex; align-items: center; gap: .5rem; padding: .25rem 0; border-bottom: 1px dashed #eee; }
		.results .idx { color: #888; min-width: 2.5rem; text-align: right; }
		.results .val { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
	</style>
	<script type="text/javascript">
	// LocalStorage keys
	const APP_KEY = 'namegen'
	const PARAMS_OPEN_KEY = APP_KEY + '.paramsOpen';
	const FAVS_KEY = APP_KEY + '.favorites';

	// UI Helpers

	// Default constants mirrored from PHP (kept in sync)
	const DEF = Object.freeze({
		t_first_extra: <?= json_encode(DEF_THRESH_FIRST_EXTRA) ?>,
		t_double_last: <?= json_encode(DEF_THRESH_DOUBLE_LAST) ?>,
		t_longer_last: <?= json_encode(DEF_THRESH_LONGER_LAST) ?>,
		t_nobility:    <?= json_encode(DEF_THRESH_NOBILITY) ?>,
		min_last:      <?= json_encode(DEF_MIN_LASTNAME_SYLL) ?>,
		max_last:      <?= json_encode(DEF_MAX_LASTNAME_SYLLX) ?>,
		gender:        "random",
		count:         10,
		mode:          ""
	});

	function resetToDefaults()
	{
		const f = document.getElementById('form');
		f.gender.value = DEF.gender;
		f.count.value = DEF.count;
		f.mode.value = DEF.mode;

		f.t_first_extra.value = DEF.t_first_extra;
		f.t_double_last.value = DEF.t_double_last;
		f.t_longer_last.value = DEF.t_longer_last;
		f.t_nobility.value    = DEF.t_nobility;

		f.min_last.value = DEF.min_last;
		f.max_last.value = DEF.max_last;

		// Uncheck stats
		f.stats.checked = false;

		// Update readouts
		document.getElementById('out_first_extra').value = fmt01(DEF.t_first_extra);
		document.getElementById('out_double_last').value = fmt01(DEF.t_double_last);
		document.getElementById('out_longer_last').value = fmt01(DEF.t_longer_last);
		document.getElementById('out_nobility').value    = fmt01(DEF.t_nobility);
		document.getElementById('out_min_last').value    = String(DEF.min_last);
		document.getElementById('out_max_last').value    = String(DEF.max_last);
	}

	function fmt01(x)
	{
		return (Math.round(x * 100) / 100).toFixed(2);
	}

	function bindRange(id, outId, factor=1)
	{
		const el = document.getElementById(id);
		const out = document.getElementById(outId);
		const update = () => { out.value = (factor === 1) ? fmt01(parseFloat(el.value)) : String(parseInt(el.value, 10)); };
		el.addEventListener('input', update);
		update();
	}

	// Favorites

	function loadFavs()
	{
		try
		{
			const raw = localStorage.getItem(FAVS_KEY);
			if (!raw) { return []; }
			const arr = JSON.parse(raw);
			return Array.isArray(arr) ? arr : [];
		}
		catch (_)
		{
			return [];
		}
	}

	function saveFavs(list)
	{
		try
		{
			localStorage.setItem(FAVS_KEY, JSON.stringify(list));
		}
		catch (_)
		{
			/* ignore quota errors */
		}
	}

	function addFav(name)
	{
		const list = loadFavs();
		if (!list.includes(name))
		{
			list.push(name);
			saveFavs(list);
		}
		renderFavs();
	}

	function removeFav(name)
	{
		const list = loadFavs().filter(v => v !== name);
		saveFavs(list);
		renderFavs();
	}

	function renderFavs()
	{
		const ul = document.getElementById('favlist');
		if (!ul) { return; }
		const favs = loadFavs();
		ul.innerHTML = '';
		favs.forEach((v, i) =>
		{
			const li = document.createElement('li');
			const idx = document.createElement('span');
			idx.className = 'idx';
			idx.textContent = String(i + 1).padStart(2, ' ') + '. ';

			const val = document.createElement('span');
			val.className = 'val';
			val.textContent = v;

			const btn = document.createElement('button');
			btn.className = 'save saved';
			btn.textContent = '★';
			btn.title = 'Von Favoriten entfernen';
			btn.addEventListener('click', function ()
			{
				removeFav(v);
			});

			li.appendChild(btn);
			li.appendChild(idx);
			li.appendChild(val);
			ul.appendChild(li);
		});
	}

	// On DOM ready	
	document.addEventListener('DOMContentLoaded', function ()
	{
		// UI: Folding Parameters section: Folding Parameters section
		const d = document.getElementById('genparams');
		if (!d) { return; }

		// Restore last state
		try
		{
			if (localStorage.getItem(PARAMS_OPEN_KEY) === '1')
			{
				d.setAttribute('open', '');
			}
		}
		catch (_) {}

		// UI: Folding Parameters section: Save on toggle
		d.addEventListener('toggle', function ()
		{
			try
			{
				localStorage.setItem(PARAMS_OPEN_KEY, d.open ? '1' : '0');
			}
			catch (_) {}
		});

		// Favorites: Wire “Save” buttons in results
		document.querySelectorAll('#results .save').forEach(btn =>
		{
			btn.addEventListener('click', function ()
			{
				const name = btn.getAttribute('data-name') || '';
				if (name)
				{
					addFav(name);
					btn.classList.add('saved');
					btn.textContent = '★';
					btn.title = 'Gespeichert';
				}
			});
		});

		// Favorites toolbar
		const btnCopy = document.getElementById('fav-copy');
		if (btnCopy)
		{
			btnCopy.addEventListener('click', async function ()
			{
				const text = loadFavs().join('\n');
				try
				{
					await navigator.clipboard.writeText(text);
				}
				catch (_)
				{
					/* clipboard may be blocked; ignore */
				}
			});
		}

		const btnExport = document.getElementById('fav-export');
		if (btnExport)
		{
			btnExport.addEventListener('click', function ()
			{
				const text = loadFavs().join('\n');
				const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
				const url = URL.createObjectURL(blob);
				const a = document.createElement('a');
				a.href = url;
				a.download = APP_KEY + '-favorites.txt';
				document.body.appendChild(a);
				a.click();
				setTimeout(function ()
				{
					URL.revokeObjectURL(url);
					document.body.removeChild(a);
				}, 0);
			});
		}

		const btnClear = document.getElementById('fav-clear');
		if (btnClear)
		{
			btnClear.addEventListener('click', function ()
			{
				if (confirm('Favoritenliste leeren'))
				{
					saveFavs([]);
					renderFavs();
				}
			});
		}

		// Initial render
		renderFavs();
	});
	</script>
</head>
<body>
	<h1><?= htmlspecialchars(SCRIPTTITLE . ' ' . SCRIPTVERSION, ENT_QUOTES) ?></h1>

	<!-- Input and parameters form -->

	<form id="form" method="get">
		<fieldset class="grid">
			<div>
				<label for="gender">Geschlecht</label>
				<select id="gender" name="gender">
					<option value="female"<?= $gender === 'female' ? ' selected' : '' ?>>Weiblich</option>
					<option value="male"<?= $gender === 'male' ? ' selected' : '' ?>>M&auml;nnlich</option>
					<option value="random"<?= $gender === 'random' ? ' selected' : '' ?>>Zuf&auml;llig</option>
				</select>
			</div>
			<div>
				<label for="count">Anzahl</label>
				<input id="count" name="count" type="number" min="1" step="1" value="<?= (int)$count ?>">
			</div>
			<div>
				<label for="mode">Modus</label>
				<select id="mode" name="mode">
					<option value=""<?= $mode === 0 ? ' selected' : '' ?>>Vor- und Nachname</option>
					<option value="firstname"<?= $mode === 1 ? ' selected' : '' ?>>Nur Vorname</option>
					<option value="lastname"<?= $mode === 2 ? ' selected' : '' ?>>Nur Nachname</option>
				</select>
			</div>
			<div>
				<label>
					<input type="checkbox" name="stats" value="1"<?= $stats ? ' checked' : '' ?>>
					Statistik zeigen
				</label>
			</div>
		</fieldset>

		<details id="genparams" class="params">
			<summary>Parameter</summary>

			<div class="grid">
				<div>
					<label for="t_first_extra">Zusätzliche Silbe in Vorname (Wahrscheinlichkeit)</label>
					<div class="range-row">
						<input id="t_first_extra" name="t_first_extra" type="range" min="0" max="1" step="0.01" value="<?= htmlspecialchars((string)$t_first_extra, ENT_QUOTES) ?>">
						<output id="out_first_extra"></output>
					</div>
					<p class="small">Typischer Wertebereich: 0.00–0.60 (Default <?= number_format(DEF_THRESH_FIRST_EXTRA, 2) ?>)</p>
				</div>

				<div>
					<label for="t_double_last">Doppel-Nachname (Wahrscheinlichkeit)</label>
					<div class="range-row">
						<input id="t_double_last" name="t_double_last" type="range" min="0" max="1" step="0.01" value="<?= htmlspecialchars((string)$t_double_last, ENT_QUOTES) ?>">
						<output id="out_double_last"></output>
					</div>
					<p class="small">Typischer Wertebereich: 0.00–0.40 (Default <?= number_format(DEF_THRESH_DOUBLE_LAST, 2) ?>)</p>
				</div>

				<div>
					<label for="t_longer_last">L&auml;ngerer Nachname (Wahrscheinlichkeit)</label>
					<div class="range-row">
						<input id="t_longer_last" name="t_longer_last" type="range" min="0" max="1" step="0.01" value="<?= htmlspecialchars((string)$t_longer_last, ENT_QUOTES) ?>">
						<output id="out_longer_last"></output>
					</div>
					<p class="small">Typischer Wertebereich: 0.00–0.60 (Default <?= number_format(DEF_THRESH_LONGER_LAST, 2) ?>)</p>
				</div>

				<div>
					<label for="t_nobility">Adelstitel (Wahrscheinlichkeit)</label>
					<div class="range-row">
						<input id="t_nobility" name="t_nobility" type="range" min="0" max="1" step="0.01" value="<?= htmlspecialchars((string)$t_nobility, ENT_QUOTES) ?>">
						<output id="out_nobility"></output>
					</div>
					<p class="small">Typischer Wertebereich: 0.00–0.50 (Default <?= number_format(DEF_THRESH_NOBILITY, 2) ?>)</p>
				</div>
			</div>

			<div class="grid-2" style="margin-top:1rem">
				<div>
					<label for="min_last">Min Silben in Nachname (inklusiv)</label>
					<div class="range-row">
						<input id="min_last" name="min_last" type="range" min="1" max="8" step="1" value="<?= (int)$min_last ?>">
						<output id="out_min_last"></output>
					</div>
					<p class="small">Default <?= DEF_MIN_LASTNAME_SYLL ?></p>
				</div>

				<div>
					<label for="max_last">Max Silben in Nachname (exklusiv)</label>
					<div class="range-row">
						<input id="max_last" name="max_last" type="range" min="2" max="10" step="1" value="<?= (int)$max_last ?>">
						<output id="out_max_last"></output>
					</div>
					<p class="small">Default <?= DEF_MAX_LASTNAME_SYLLX ?> (Muss gr&ouml;&szlig;er sein als 'min')</p>
				</div>
			</div>
		</details>

		<hr>

		<p style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap">
			<button type="submit">Generieren!</button>
			<button type="button" onclick="resetToDefaults()">Zur&uuml;cksetzen</button>
		</p>
	</form>

	<script type="text/javascript">
	// Bind sliders to readouts
	bindRange('t_first_extra', 'out_first_extra');
	bindRange('t_double_last', 'out_double_last');
	bindRange('t_longer_last', 'out_longer_last');
	bindRange('t_nobility',    'out_nobility');
	bindRange('min_last',      'out_min_last', 0);
	bindRange('max_last',      'out_max_last', 0);
	</script>

	<!-- Results or error message -->

	<?php if (!$loaded): ?>
		<p class="err">Konnte <code><?= htmlspecialchars(DATAFILENAME, ENT_QUOTES) ?></code> im aktuellen Ordner nicht laden.</p>
	<?php else: ?>
		<?php if ($stats): ?>
			<h2>Statistik</h2>
			<pre><?php ob_start(); $gen->printStatistics($gen->computeStats()); echo htmlspecialchars(ob_get_clean(), ENT_QUOTES); ?></pre>
		<?php else: ?>
			<h2>Namen</h2>
			<ul id="results" class="results">
			<?php
				for ($i = 0; $i < $count; ++$i)
				{
					$prefix = ($count > 1) ? str_pad((string)($i + 1), 2, ' ', STR_PAD_LEFT) . '. ' : '';
					$name = $prefix . $gen->generate($gender, $mode) . "\n";

					echo '<li>';
					echo    '<button class="save" data-name="' . htmlspecialchars($name, ENT_QUOTES) . '" title="Favorit speichern">☆</button> ';
					echo    '<span class="idx">' . htmlspecialchars($prefix, ENT_QUOTES) . '</span>';
					echo    '<span class="val">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
					echo '</li>';
				}
			?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Favorites list -->

	<details id="favorites" class="params">
		<summary>Favoriten</summary>
		<div class="content">
			<ul id="favlist" class="results"></ul>
			<p style="margin-top:.75rem; display:flex; gap:.5rem; flex-wrap:wrap">
				<button type="button" id="fav-copy">Kopieren</button>
				<button type="button" id="fav-export">Exportiere .txt</button>
				<button type="button" id="fav-clear">Leeren</button>
			</p>
		</div>
	</details>

	<!-- Link to other generator script -->

 	<?php
	$otherApp = __DIR__ . '/../citynamegen/index.php';
	if (file_exists($otherApp))
	{
		echo '<p>Versuch mal den <a href="../citynamegen/">City Name Generator</a>!</p>';
	}
	?>
	<p class="footer">&copy; 2025 by <a href="https://www.frankwilleke.de">www.frankwilleke.de</a></p>
</body>
</html>
