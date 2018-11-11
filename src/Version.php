<?php

declare(strict_types=1);

/*
 * This file is part of the Rollerworks Semver package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Version;

final class Version
{
    /**
     * Match most common version formats.
     *
     * * No prefix or build-meta (matched)
     * * For historic reasons stability versions may have a hyphen or dot
     *   and is considered optional
     */
    public const VERSION_REGEX = '(?P<major>\d++)\.(?P<minor>\d++)(?:\.(?P<patch>\d++))?(?:[-.]?(?P<stability>beta|RC|alpha|stable)(?:[.-]?(?P<metaver>\d+))?)?';

    public $major;
    public $minor;
    public $patch;
    public $stability;
    public $metaver;
    public $full;

    /**
     * Higher means more stable.
     *
     * @var int[]
     */
    private static $stabilityIndexes = ['alpha' => 0, 'beta' => 1, 'rc' => 2, 'stable' => 3];

    private function __construct(int $major, int $minor, int $patch, int $stability, int $metaver = 0)
    {
        if (0 === $major) {
            $stability = 0;
            $metaver = 1;
        }

        $this->major = $major;
        $this->minor = $minor;
        $this->patch = $patch;
        $this->stability = $stability;
        $this->metaver = $metaver;

        if (3 === $stability && $this->metaver > 0) {
            throw new \InvalidArgumentException('Meta version of the stability flag cannot be set for stable.');
        }

        if ($major > 0 && $stability < 3) {
            $this->full = sprintf(
                '%d.%d.%d-%s%d',
                $this->major,
                $this->minor,
                $this->patch,
                strtoupper(array_search($this->stability, self::$stabilityIndexes, true)),
                $this->metaver
            );
        } else {
            $this->full = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
        }
    }

    public function __toString()
    {
        return $this->full;
    }

    public static function fromString(string $version): self
    {
        if (preg_match('/^v?'.self::VERSION_REGEX.'$/i', $version, $matches)) {
            return new self(
                (int) $matches['major'],
                (int) $matches['minor'],
                (int) ($matches['patch'] ?? 0),
                self::$stabilityIndexes[strtolower($matches['stability'] ?? 'stable')],
                (int) ($matches['metaver'] ?? 0)
            );
        }

        throw new \InvalidArgumentException(
            sprintf(
                'Unable to parse version "%s" Expects an SemVer compatible version without build-metadata. '.
                'Eg. "1.0.0", "1.0", "1.0" or "1.0.0-beta1", "1.0.0-beta-1"',
                $version
            )
        );
    }

    /**
     * Returns a list of possible feature versions.
     *
     * * 0.1.0 -> [0.1.1, 0.2.0, 1.0.0-beta1, 1.0.0]
     * * 1.0.0 -> [1.0.1, 1.1.0, 2.0.0-beta1, 2.0.0]
     * * 1.0.1 -> [1.0.2, 1.2.0, 2.0.0-beta1, 2.0.0]
     * * 1.1.0 -> [1.2.0, 1.2.0-beta1, 2.0.0-beta1, 2.0.0]
     * * 1.0.0-beta1 -> [1.0.0-beta2, 1.0.0] (no minor or major increases)
     * * 1.0.0-alpha1 -> [1.0.0-alpha2, 1.0.0-beta1, 1.0.0] (no minor or major increases)
     *
     * @return Version[]
     */
    public function getNextVersionCandidates(): array
    {
        $candidates = [];

        // Pre first-stable, so 0.x-[rc,beta,stable] releases are not considered.
        // Use alpha as stability with metaver 1, 0.2-alpha2 is simple ignored.
        // If anyone really uses this... not our problem :)
        if (0 === $this->major) {
            $candidates[] = new self(0, $this->minor, $this->patch + 1, 0, 1); // patch increase
            $candidates[] = new self(0, $this->minor + 1, 0, 0, 1); // minor increase
            $candidates[] = new self(1, 0, 0, 1, 1); // 1.0.0-BETA1

            // stable (RC usually follows *after* beta, but jumps to stable are accepted)
            // RC is technically valid, but not very common and therefor ignored.
            $candidates[] = new self(1, 0, 0, 3);

            // No future candidates considered.
            return $candidates;
        }

        // Latest is unstable, may increase stability or metaver (nothing else)
        // 1.0.1-beta1 is not accepted, an (un)stability only applies for x.0.0
        if ($this->stability < 3) {
            $candidates[] = new self($this->major, $this->minor, 0, $this->stability, $this->metaver + 1);

            for ($s = $this->stability + 1; $s < 3; ++$s) {
                $candidates[] = new self($this->major, $this->minor, 0, $s, 1);
            }

            $candidates[] = new self($this->major, $this->minor, 0, 3);

            return $candidates;
        }

        // Stable, so a patch, major or new minor (with lower stability) version is possible
        // RC is excluded.
        $candidates[] = new self($this->major, $this->minor, $this->patch + 1, 3);
        $candidates[] = new self($this->major, $this->minor + 1, 0, 1, 1); // BETA1 for next minor
        $candidates[] = new self($this->major, $this->minor + 1, 0, 3); // stable next minor

        // New (un)stable major (excluding RC)
        $candidates[] = new self($this->major + 1, 0, 0, 0, 1); // alpha
        $candidates[] = new self($this->major + 1, 0, 0, 1, 1); // beta
        $candidates[] = new self($this->major + 1, 0, 0, 3); // stable

        return $candidates;
    }

    public function equalTo(self $second): bool
    {
        return $this->full === $second->full;
    }

    /**
     * Returns the increased Version based on the stability.
     *
     * Note. Using 'major' on a beta release will create a stable release
     * for that major version. Using 'stable' on an existing stable will increase
     * minor. Using 'patch' on an unstable release will increase the metaver instead.
     *
     * @param string $stability Eg. alpha, beta, rc, stable, major, minor, patch
     *
     * @return Version A new version instance with the changes applied
     */
    public function increase(string $stability): self
    {
        switch ($stability) {
            case 'patch':
                if ($this->major > 0 && $this->metaver > 0) {
                    return $this->increaseNext();
                }

                return new self($this->major, $this->minor, $this->patch + 1, 3);

            case 'minor':
                return new self($this->major, $this->minor + 1, 0, 3);

            case 'major':
                if ($this->stability < 3) {
                    return new self($this->major > 0 ? $this->major : $this->major + 1, 0, 0, 3);
                }

                return new self($this->major + 1, 0, 0, 3);

            case 'alpha':
            case 'beta':
            case 'rc':
                return $this->increaseMetaver($stability);

            case 'stable':
                return $this->increaseStable();

            case 'next':
                return $this->increaseNext();

            default:
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unknown stability "%s", accepts "%s".',
                        $stability,
                        implode('", "', ['alpha', 'beta', 'rc', 'stable', 'major', 'next', 'minor', 'patch'])
                    )
                );
        }
    }

    private function increaseMetaver(string $stability): self
    {
        if ($this->stability === self::$stabilityIndexes[$stability]) {
            return new self($this->major, $this->minor, 0, $this->stability, $this->metaver + 1);
        }

        if (self::$stabilityIndexes[$stability] > $this->stability) {
            return new self($this->major, $this->minor, 0, self::$stabilityIndexes[$stability], 1);
        }

        return new self($this->major, $this->minor + 1, 0, self::$stabilityIndexes[$stability], 1);
    }

    private function increaseStable(): self
    {
        if ($this->stability < 3) {
            return new self(max($this->major, 1), 0, 0, 3);
        }

        return new self($this->major, $this->minor + 1, 0, 3);
    }

    private function increaseNext(): self
    {
        if ($this->major > 0 && $this->stability < 3) {
            return new self($this->major, $this->minor, $this->patch, $this->stability, $this->metaver + 1);
        }

        return new self($this->major, $this->minor + 1, 0, 3);
    }
}
