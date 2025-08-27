<?php
declare(strict_types=1);

/// @brief   German Name Generator (PHP port, web-only)
/// @details Loads namegen_data.json from the same folder and renders a form to generate names or show stats.
/// @author  Frank Willeke

// --------------------------------------------------------------------------------------
// Config / Metadata
// --------------------------------------------------------------------------------------

/** @var string */
const SCRIPTTITLE = 'German Name Generator';
/** @var string */
const SCRIPTVERSION = '1.7.3';
/** @var string */
const DATAFILENAME = 'namegen_data.json';

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

// --------------------------------------------------------------------------------------
// Name Generator
// --------------------------------------------------------------------------------------

/**
 * @brief	Name generator core class.
 */
final class NameGenerator
{
	// Thresholds (same semantics as the Python version)
	private float $_threshExtraFirstnameSyllable = 0.31;
	private float $_threshDoubleLastName = 0.18;
	private float $_threshLongerLastName = 0.30;
	private float $_threshNobility = 0.29;

	// Limits / Ranges
	private int $_minLastnameSyllables = 2;
	private int $_maxLastnameSyllables = 4; // exclusive upper bound in Python's randrange(2,4)

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
		return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
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

		return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
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

$gender_default='random';
$count_default=10;
$mode_default=''; // 0=full, 1=firstname only, 2=lastname only

$gender = isset($_GET['gender']) ? (string)$_GET['gender'] : $gender_default;
$count = isset($_GET['count']) ? max(1, (int)$_GET['count']) : $count_default;
$modeStr = isset($_GET['mode']) ? (string)$_GET['mode'] : $mode_default;
$stats = isset($_GET['stats']);

$mode = 0;
if ($modeStr === 'firstname') { $mode = 1; }
elseif ($modeStr === 'lastname') { $mode = 2; }

$gen = new NameGenerator();
$dataFile = __DIR__ . DIRECTORY_SEPARATOR . DATAFILENAME;
$loaded = $gen->loadData($dataFile);

mt_srand((int)microtime(true));
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars(SCRIPTTITLE . ' ' . SCRIPTVERSION, ENT_QUOTES) ?></title>
	<style>
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
		fieldset { padding: 1rem; border-radius: 8px; }
		label { display: block; margin: 0.5rem 0 0.25rem; }
		input[type="number"] { width: 7rem; }
		pre { background: #111; color: #0f0; padding: 1rem; border-radius: 8px; overflow: auto; }
		.err { background: #fee; color: #900; padding: 0.75rem; border: 1px solid #f99; border-radius: 8px; }
		.footer { font-size: 0.75em; }
		.grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 1rem; }
		button { padding: .6rem 1rem; border-radius: 10px; border: 1px solid #ccc; color: #111; -webkit-text-fill-color: #111; background: #f6f6f6; -webkit-appearance: none; appearance: none; cursor: pointer; }
		button:hover { background: #eee; }
	</style>
</head>
<body>
	<h1><?= htmlspecialchars(SCRIPTTITLE . ' ' . SCRIPTVERSION, ENT_QUOTES) ?></h1>

	<form method="get">
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
				<label for="mode">Modus</label>
				<select id="mode" name="mode">
					<option value=""<?= $mode === 0 ? ' selected' : '' ?>>Vor- und Nachname</option>
					<option value="firstname"<?= $mode === 1 ? ' selected' : '' ?>>Nur Vorname</option>
					<option value="lastname"<?= $mode === 2 ? ' selected' : '' ?>>Nor Nachname</option>
				</select>
			</div>
			<div>
				<label for="count">Anzahl</label>
				<input id="count" name="count" type="number" min="1" step="1" value="<?= (int)$count ?>">
			</div>
			<div>
				<label>
					<input type="checkbox" name="stats" value="1"<?= $stats ? ' checked' : '' ?>>
					Statistik anzeigen
				</label>
			</div>
		</fieldset>
		<p style="margin-top:1rem">
			<button type="submit">Generieren!</button>
		</p>
	</form>

	<?php if (!$loaded): ?>
		<p class="err">Could not load <code><?= htmlspecialchars(DATAFILENAME, ENT_QUOTES) ?></code> from this folder.</p>
	<?php else: ?>
		<?php if ($stats): ?>
			<h2>Statistik</h2>
			<pre><?php ob_start(); $gen->printStatistics($gen->computeStats()); echo htmlspecialchars(ob_get_clean(), ENT_QUOTES); ?></pre>
		<?php else: ?>
			<h2>Namen</h2>
			<pre><?php
				$out = '';
				for ($i = 0; $i < $count; ++$i)
				{
					$prefix = ($count > 1) ? str_pad((string)($i + 1), 2, ' ', STR_PAD_LEFT) . '. ' : '';
					$out .= $prefix . $gen->generate($gender, $mode) . "\n";
				}
				echo htmlspecialchars($out, ENT_QUOTES);
			?></pre>
		<?php endif; ?>
	<?php endif; ?>
	<p class="footer">&copy; 2025 by <a href="https://www.frankwilleke.de">www.frankwilleke.de</a></p>
</body>
</html>
