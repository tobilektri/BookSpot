/**
 * Chart colours (validated with the dataviz palette checker):
 * categorical slots 1–4 in fixed order, separate steps for dark mode.
 */
const light = {
  series: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'],
  rest: '#d6d3d1',
  surface: '#ffffff',
  text: '#57534e',
  grid: '#e7e5e4',
}
const dark = {
  series: ['#3987e5', '#d95926', '#199e70', '#c98500'],
  rest: '#44403c',
  surface: '#1c1917',
  text: '#d6d3d1',
  grid: '#292524',
}

export function chartTheme() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? dark : light
}

/** Reading status -> fixed categorical slot (colour follows the entity). */
export const statusSlot = { read: 0, unread: 1, reading: 2, abandoned: 3 }

export function baseOptions(theme) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    plugins: {
      legend: { display: false },
      tooltip: { backgroundColor: '#1c1917', titleColor: '#fafaf9', bodyColor: '#fafaf9', padding: 10, cornerRadius: 8, displayColors: true },
    },
    scales: {
      x: { grid: { display: false }, border: { color: theme.grid }, ticks: { color: theme.text } },
      y: { beginAtZero: true, grid: { color: theme.grid }, border: { display: false }, ticks: { color: theme.text, precision: 0 } },
    },
  }
}
