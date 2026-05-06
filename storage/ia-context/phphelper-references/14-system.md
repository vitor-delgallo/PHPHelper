# System Helper

Class: `VD\PHPHelper\System`
Source file: `src/System.php`

Use for memory conversions, memory usage, limit comparison, pseudo-random seed, and simple timers.

## Methods

| Method | Use |
| --- | --- |
| `getMemoryUnitOrder()` | Returns the order of memory units. |
| `convertBytesToReadable(int $bytes)` | Converts bytes into readable text. |
| `convertMemoryToBytes(?string $input)` | Converts strings like `128M` or `1G` to bytes. |
| `getServerMemoryUsage()` | Attempts to return server memory usage. |
| `getMemoryUsage()` | Returns PHP process memory usage. |
| `isMemoryGreaterThan(?string $memoryA, ?string $memoryB)` | Compares two memory measures. |
| `makeSeed(?string $seed = null)` | Defines a pseudo-random seed. |
| `timer(?string $timerName, string $type = "init")` | Creates/queries a named timer. |

## Cautions

- Memory information can vary by operating system and PHP environment.
- `makeSeed` changes pseudo-random behavior; avoid in shared code without justification.
- Timers are simple utilities and do not replace structured profiling.
