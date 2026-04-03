<?php

declare(strict_types=1);

final class GeomagWmm {
    private float $epoch;
    private int $nMax;

    /** @var array<int, float> */
    private array $g = [];
    /** @var array<int, float> */
    private array $h = [];
    /** @var array<int, float> */
    private array $dg = [];
    /** @var array<int, float> */
    private array $dh = [];

    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(float $epoch, int $nMax, array $g, array $h, array $dg, array $dh) {
        $this->epoch = $epoch;
        $this->nMax = $nMax;
        $this->g = $g;
        $this->h = $h;
        $this->dg = $dg;
        $this->dh = $dh;
    }

    public static function fromCofFile(string $path): self {
        $real = realpath($path) ?: $path;
        $mtime = @filemtime($path) ?: 0;
        $key = $real . ':' . $mtime;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read WMM coefficient file: $path");
        }

        $lines = preg_split("/\\r\\n|\\n|\\r/", $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
        if (count($lines) < 2) {
            throw new RuntimeException("Invalid WMM coefficient file: $path");
        }

        $headerParts = preg_split('/\\s+/', trim($lines[0])) ?: [];
        $epoch = isset($headerParts[0]) ? (float)$headerParts[0] : 0.0;
        if (!is_finite($epoch) || $epoch <= 0) {
            throw new RuntimeException("Invalid WMM epoch in coefficient file: $path");
        }

        $coeffs = [];
        $nMax = 0;
        for ($i = 1; $i < count($lines); $i++) {
            $parts = preg_split('/\\s+/', trim($lines[$i])) ?: [];
            if (count($parts) < 6) {
                continue;
            }

            $n = (int)$parts[0];
            $m = (int)$parts[1];
            $gnm = (float)$parts[2];
            $hnm = (float)$parts[3];
            $dgnm = (float)$parts[4];
            $dhnm = (float)$parts[5];

            if ($n <= 0 || $m < 0 || $m > $n) {
                continue;
            }
            $nMax = max($nMax, $n);
            $coeffs[] = [$n, $m, $gnm, $hnm, $dgnm, $dhnm];
        }

        if ($nMax <= 0) {
            throw new RuntimeException("No coefficients found in: $path");
        }

        $numTerms = (int)(($nMax + 1) * ($nMax + 2) / 2);
        $g = array_fill(0, $numTerms + 1, 0.0);
        $h = array_fill(0, $numTerms + 1, 0.0);
        $dg = array_fill(0, $numTerms + 1, 0.0);
        $dh = array_fill(0, $numTerms + 1, 0.0);

        foreach ($coeffs as [$n, $m, $gnm, $hnm, $dgnm, $dhnm]) {
            $idx = (int)($n * ($n + 1) / 2 + $m);
            $g[$idx] = (float)$gnm;
            $h[$idx] = (float)$hnm;
            $dg[$idx] = (float)$dgnm;
            $dh[$idx] = (float)$dhnm;
        }

        $model = new self($epoch, $nMax, $g, $h, $dg, $dh);
        self::$cache = [$key => $model] + self::$cache;
        return $model;
    }

    public function declinationDegrees(float $latDeg, float $lonDeg, float $decimalYear, float $altKm = 0.0): float {
        $dt = $decimalYear - $this->epoch;
        if (!is_finite($dt)) {
            throw new RuntimeException('Invalid decimal year');
        }

        // WGS84 ellipsoid (kilometers) and WMM reference radius.
        $a = 6378.137;
        $b = 6356.7523142;
        $epssq = 1.0 - ($b * $b) / ($a * $a);
        $re = 6371.2;

        $coordGeo = [
            'phi' => $latDeg,
            'lambda' => $lonDeg,
            'h' => $altKm,
        ];
        $coordSph = $this->geodeticToSpherical($a, $epssq, $coordGeo);

        $nMax = $this->nMax;
        $numTerms = (int)(($nMax + 1) * ($nMax + 2) / 2);

        $sphVars = $this->computeSphericalHarmonicVariables($re, $coordSph['r'], $coordSph['lambda'], $nMax);
        $legendre = $this->associatedLegendreFunction($coordSph['phig'], $nMax, $numTerms);

        $sph = $this->summation($dt, $sphVars, $legendre, $coordSph, $nMax);
        $geo = $this->rotateMagneticVector($coordSph, $coordGeo, $sph);

        $decl = rad2deg(atan2($geo['By'], $geo['Bx']));
        if (!is_finite($decl)) {
            throw new RuntimeException('Declination computation failed');
        }
        return $decl;
    }

    private function geodeticToSpherical(float $a, float $epssq, array $coordGeodetic): array {
        $cosLat = cos(deg2rad((float)$coordGeodetic['phi']));
        $sinLat = sin(deg2rad((float)$coordGeodetic['phi']));

        $rc = $a / sqrt(1.0 - $epssq * $sinLat * $sinLat);
        $xp = ($rc + (float)$coordGeodetic['h']) * $cosLat;
        $zp = ($rc * (1.0 - $epssq) + (float)$coordGeodetic['h']) * $sinLat;

        $r = sqrt($xp * $xp + $zp * $zp);
        $phig = rad2deg(asin($zp / $r));

        return [
            'r' => $r,
            'phig' => $phig,
            'lambda' => (float)$coordGeodetic['lambda'],
        ];
    }

    private function computeSphericalHarmonicVariables(float $re, float $r, float $lambdaDeg, int $nMax): array {
        $cosLambda = cos(deg2rad($lambdaDeg));
        $sinLambda = sin(deg2rad($lambdaDeg));

        $relativeRadiusPower = array_fill(0, $nMax + 1, 0.0);
        $cosMLambda = array_fill(0, $nMax + 1, 0.0);
        $sinMLambda = array_fill(0, $nMax + 1, 0.0);

        $ratio = $re / $r;
        $relativeRadiusPower[0] = $ratio * $ratio;
        for ($n = 1; $n <= $nMax; $n++) {
            $relativeRadiusPower[$n] = $relativeRadiusPower[$n - 1] * $ratio;
        }

        $cosMLambda[0] = 1.0;
        $sinMLambda[0] = 0.0;
        if ($nMax >= 1) {
            $cosMLambda[1] = $cosLambda;
            $sinMLambda[1] = $sinLambda;
        }
        if ($nMax >= 2) {
            for ($m = 2; $m <= $nMax; $m++) {
                $cosMLambda[$m] = $cosMLambda[$m - 1] * $cosLambda - $sinMLambda[$m - 1] * $sinLambda;
                $sinMLambda[$m] = $cosMLambda[$m - 1] * $sinLambda + $sinMLambda[$m - 1] * $cosLambda;
            }
        }

        return [
            'RelativeRadiusPower' => $relativeRadiusPower,
            'cos_mlambda' => $cosMLambda,
            'sin_mlambda' => $sinMLambda,
        ];
    }

    private function associatedLegendreFunction(float $phigDeg, int $nMax, int $numTerms): array {
        $x = sin(deg2rad($phigDeg));
        return $this->pcupLow($x, $nMax, $numTerms);
    }

    private function pcupLow(float $x, int $nMax, int $numTerms): array {
        $Pcup = array_fill(0, $numTerms + 1, 0.0);
        $dPcup = array_fill(0, $numTerms + 1, 0.0);
        $Pcup[0] = 1.0;
        $dPcup[0] = 0.0;

        $z = sqrt((1.0 - $x) * (1.0 + $x));

        $schmidt = array_fill(0, $numTerms + 1, 0.0);
        $schmidt[0] = 1.0;

        for ($n = 1; $n <= $nMax; $n++) {
            for ($m = 0; $m <= $n; $m++) {
                $idx = (int)($n * ($n + 1) / 2 + $m);
                if ($n === $m) {
                    $idx1 = (int)(($n - 1) * $n / 2 + $m - 1);
                    $Pcup[$idx] = $z * $Pcup[$idx1];
                    $dPcup[$idx] = $z * $dPcup[$idx1] + $x * $Pcup[$idx1];
                } elseif ($n === 1 && $m === 0) {
                    $idx1 = (int)(($n - 1) * $n / 2 + $m);
                    $Pcup[$idx] = $x * $Pcup[$idx1];
                    $dPcup[$idx] = $x * $dPcup[$idx1] - $z * $Pcup[$idx1];
                } elseif ($n > 1 && $n !== $m) {
                    $idx1 = (int)(($n - 2) * ($n - 1) / 2 + $m);
                    $idx2 = (int)(($n - 1) * $n / 2 + $m);
                    if ($m > $n - 2) {
                        $Pcup[$idx] = $x * $Pcup[$idx2];
                        $dPcup[$idx] = $x * $dPcup[$idx2] - $z * $Pcup[$idx2];
                    } else {
                        $k = (float)((($n - 1) * ($n - 1) - ($m * $m)) / ((2 * $n - 1) * (2 * $n - 3)));
                        $Pcup[$idx] = $x * $Pcup[$idx2] - $k * $Pcup[$idx1];
                        $dPcup[$idx] = $x * $dPcup[$idx2] - $z * $Pcup[$idx2] - $k * $dPcup[$idx1];
                    }
                }
            }
        }

        for ($n = 1; $n <= $nMax; $n++) {
            $idx0 = (int)($n * ($n + 1) / 2);
            $idx1 = (int)(($n - 1) * $n / 2);
            $schmidt[$idx0] = $schmidt[$idx1] * (float)(2 * $n - 1) / (float)$n;

            for ($m = 1; $m <= $n; $m++) {
                $idx = (int)($n * ($n + 1) / 2 + $m);
                $idxPrev = $idx - 1;
                $schmidt[$idx] = $schmidt[$idxPrev] * sqrt((float)(($n - $m + 1) * ($m === 1 ? 2 : 1)) / (float)($n + $m));
            }
        }

        for ($n = 1; $n <= $nMax; $n++) {
            for ($m = 0; $m <= $n; $m++) {
                $idx = (int)($n * ($n + 1) / 2 + $m);
                $Pcup[$idx] *= $schmidt[$idx];
                $dPcup[$idx] = -$dPcup[$idx] * $schmidt[$idx];
            }
        }

        return ['Pcup' => $Pcup, 'dPcup' => $dPcup];
    }

    private function summation(float $dt, array $sphVars, array $legendre, array $coordSph, int $nMax): array {
        $Bx = 0.0;
        $By = 0.0;
        $Bz = 0.0;

        $rrp = $sphVars['RelativeRadiusPower'];
        $cosMLambda = $sphVars['cos_mlambda'];
        $sinMLambda = $sphVars['sin_mlambda'];
        $Pcup = $legendre['Pcup'];
        $dPcup = $legendre['dPcup'];

        for ($n = 1; $n <= $nMax; $n++) {
            for ($m = 0; $m <= $n; $m++) {
                $idx = (int)($n * ($n + 1) / 2 + $m);
                $g = $this->g[$idx] + $dt * $this->dg[$idx];
                $h = $this->h[$idx] + $dt * $this->dh[$idx];

                $tmp = $g * $cosMLambda[$m] + $h * $sinMLambda[$m];
                $Bz -= $rrp[$n] * $tmp * (float)($n + 1) * $Pcup[$idx];
                $By += $rrp[$n] * ($g * $sinMLambda[$m] - $h * $cosMLambda[$m]) * (float)$m * $Pcup[$idx];
                $Bx -= $rrp[$n] * $tmp * $dPcup[$idx];
            }
        }

        $cosPhi = cos(deg2rad((float)$coordSph['phig']));
        if (abs($cosPhi) > 1.0e-10) {
            $By /= $cosPhi;
        } else {
            $By = $this->summationSpecial($dt, $sphVars, $coordSph, $nMax);
        }

        return ['Bx' => $Bx, 'By' => $By, 'Bz' => $Bz];
    }

    private function summationSpecial(float $dt, array $sphVars, array $coordSph, int $nMax): float {
        $PcupS = array_fill(0, $nMax + 1, 0.0);
        $PcupS[0] = 1.0;
        $schmidt1 = 1.0;
        $By = 0.0;

        $sinPhi = sin(deg2rad((float)$coordSph['phig']));
        for ($n = 1; $n <= $nMax; $n++) {
            $idx = (int)($n * ($n + 1) / 2 + 1);
            $schmidt2 = $schmidt1 * (float)(2 * $n - 1) / (float)$n;
            $schmidt3 = $schmidt2 * sqrt((float)($n * 2) / (float)($n + 1));
            $schmidt1 = $schmidt2;

            if ($n === 1) {
                $PcupS[$n] = $PcupS[$n - 1];
            } else {
                $k = (float)((($n - 1) * ($n - 1) - 1) / ((2 * $n - 1) * (2 * $n - 3)));
                $PcupS[$n] = $sinPhi * $PcupS[$n - 1] - $k * $PcupS[$n - 2];
            }

            $g = $this->g[$idx] + $dt * $this->dg[$idx];
            $h = $this->h[$idx] + $dt * $this->dh[$idx];
            $By += $sphVars['RelativeRadiusPower'][$n]
                * ($g * $sphVars['sin_mlambda'][1] - $h * $sphVars['cos_mlambda'][1])
                * $PcupS[$n]
                * $schmidt3;
        }

        return $By;
    }

    private function rotateMagneticVector(array $coordSph, array $coordGeo, array $magSph): array {
        $psi = deg2rad((float)$coordSph['phig'] - (float)$coordGeo['phi']);
        $Bz = $magSph['Bx'] * sin($psi) + $magSph['Bz'] * cos($psi);
        $Bx = $magSph['Bx'] * cos($psi) - $magSph['Bz'] * sin($psi);
        $By = $magSph['By'];
        return ['Bx' => $Bx, 'By' => $By, 'Bz' => $Bz];
    }
}

