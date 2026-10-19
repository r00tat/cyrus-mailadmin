import { createTheme, type Theme } from '@mui/material/styles';

/**
 * One theme, defined explicitly, rather than MUI's defaults (U-73).
 *
 * The palette is deliberately sober and close to what an administrator of the
 * predecessor expects: a blue-grey frame, no accent colour competing with the
 * destructive actions this tool is full of. `error` therefore stays the only
 * loud colour on screen — deleting a mailbox should not have to compete for
 * attention with a decorative header.
 *
 * The font is Roboto, **bundled locally** (`@fontsource/roboto`, imported in
 * `main.tsx`). MUI's documented setup loads it from the Google Fonts CDN, which
 * `default-src 'self'` forbids and U-35 point 3 rules out explicitly. The stack
 * still names system fallbacks so that a failed font request degrades to a
 * readable page rather than to Times New Roman.
 */
export function createAppTheme(): Theme {
  return createTheme({
    palette: {
      mode: 'light',
      primary: { main: '#20516d' },
      secondary: { main: '#546e7a' },
      error: { main: '#b3261e' },
      background: { default: '#f4f6f8', paper: '#ffffff' },
    },
    shape: { borderRadius: 6 },
    typography: {
      fontFamily: [
        'Roboto',
        '-apple-system',
        'BlinkMacSystemFont',
        'Segoe UI',
        'Helvetica',
        'Arial',
        'sans-serif',
      ].join(','),
      button: { textTransform: 'none' },
    },
    components: {
      MuiButton: {
        defaultProps: { disableElevation: true },
      },
      MuiTextField: {
        defaultProps: { size: 'small', variant: 'outlined' },
      },
      MuiAppBar: {
        defaultProps: { elevation: 0 },
      },
      MuiTableCell: {
        styleOverrides: {
          // Tables are the bulk of this interface (domains, accounts,
          // addresses). Server-side sorting and pagination through MUI's own
          // Table, TableSortLabel and TablePagination — not the DataGrid, whose
          // paid tiers are out of the question and whose MIT tier buys little
          // once sorting happens on the server anyway (U-74).
          head: { fontWeight: 600 },
        },
      },
    },
  });
}
