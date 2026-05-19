"""Synthesises a seamless-looping emergency wail siren as 16-bit mono WAV.

Frequency wails between 600-1400 Hz; duration is an integer number of
modulation periods and the centre frequency is chosen so the waveform phase
closes cleanly at the loop boundary (no click when looped).
"""
import math
import struct
import wave

SAMPLE_RATE = 22050
DURATION = 10.0          # seconds
CENTRE_HZ = 1000.0       # fc * DURATION must be an integer for a seamless loop
DEVIATION_HZ = 400.0
MOD_PERIOD = 2.0         # DURATION must be an integer multiple of this
AMPLITUDE = 0.9

OUTPUTS = [
    "../luilaykhao-app/android/app/src/main/res/raw/sos_siren.wav",
    "../luilaykhao-app/assets/audio/sos_siren.wav",
]


def render() -> bytes:
    total = int(SAMPLE_RATE * DURATION)
    k = DEVIATION_HZ * MOD_PERIOD / (2 * math.pi)
    frames = bytearray()
    for i in range(total):
        t = i / SAMPLE_RATE
        # Instantaneous phase = 2*pi * integral of f(t); f wails sinusoidally.
        phi = 2 * math.pi * (CENTRE_HZ * t - k * math.cos(2 * math.pi * t / MOD_PERIOD) + k)
        sample = AMPLITUDE * (0.8 * math.sin(phi) + 0.2 * math.sin(3 * phi))
        frames += struct.pack("<h", int(max(-1.0, min(1.0, sample)) * 32767))
    return bytes(frames)


def main() -> None:
    pcm = render()
    for path in OUTPUTS:
        with wave.open(path, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(SAMPLE_RATE)
            w.writeframes(pcm)
        print(f"wrote {path} ({len(pcm)} bytes pcm)")


if __name__ == "__main__":
    main()
