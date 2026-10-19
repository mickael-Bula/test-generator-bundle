<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Util;

final class TimeFormater
{
    /**
     * @return list<array<string, string>>
     * @throws \Exception
     */
    public function displayExecutionTime(\DateTimeImmutable $startTime, float $startMicrotime): array
    {
        $formatedStartTime = $startTime->format('H:i:s');
        $formatedEndTime = (new \DateTimeImmutable())->format('H:i:s');
        $durationInSeconds = round(microtime(true) - $startMicrotime, 2);

        if ($durationInSeconds >= 60) {
            $minutes = (int) ($durationInSeconds / 60);
            $seconds = round(fmod($durationInSeconds, 60), 1);
            $formattedDuration = sprintf('%dm %ss', $minutes, $seconds);
        } else {
            $formattedDuration = sprintf('%.2fs', $durationInSeconds);
        }

        return [
            ['Début' => $formatedStartTime],
            ['Fin' => $formatedEndTime],
            ['Durée totale' => $formattedDuration],
        ];
    }
}
