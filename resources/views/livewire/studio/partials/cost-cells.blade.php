<td class="px-3 py-2 text-right">{{ number_format($row['calls']) }}</td>
<td class="px-3 py-2 text-right">{{ number_format($row['input']) }}</td>
<td class="px-3 py-2 text-right">{{ number_format($row['output']) }}</td>
<td class="px-3 py-2 text-right">{{ number_format($row['cache_write']) }}</td>
<td class="px-3 py-2 text-right">{{ number_format($row['cache_read']) }}</td>
<td class="px-3 py-2 text-right">${{ number_format($row['cost'], 2) }}</td>
<td class="px-3 py-2 text-right">${{ number_format($row['average'], 4) }}</td>
