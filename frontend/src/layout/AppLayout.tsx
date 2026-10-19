import AccountCircleIcon from '@mui/icons-material/AccountCircle';
import KeyIcon from '@mui/icons-material/Key';
import LogoutIcon from '@mui/icons-material/Logout';
import MenuIcon from '@mui/icons-material/Menu';
import AppBar from '@mui/material/AppBar';
import Box from '@mui/material/Box';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import ListSubheader from '@mui/material/ListSubheader';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Toolbar from '@mui/material/Toolbar';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { Fragment, useState, type ReactNode } from 'react';
import { Link as RouterLink, Outlet, useLocation, useNavigate } from 'react-router-dom';

import { isActive, visibleNavigation } from '../app/navigation';
import { useSession } from '../auth/SessionContext';
import { useTranslation } from '../i18n';

/**
 * The frame every signed-in view renders inside: a bar, a navigation drawer and
 * the account menu that signs out.
 *
 * Navigation entries carry the permission that makes them useful. Hiding one is
 * courtesy, not enforcement — the backend checks the same permission on every
 * request, and a view reached anyway says so (`RequirePermission`,
 * architecture 11.2). The entries themselves are data in `app/navigation.tsx`.
 */

const DRAWER_WIDTH = 240;

/** Declared by the admins view (`pages/admins/routes.tsx`), as in the contract. */
const OWN_PASSWORD_PATH = '/admins/me/password';

export function AppLayout(): ReactNode {
  const { t } = useTranslation();
  const theme = useTheme();
  const isWide = useMediaQuery(theme.breakpoints.up('md'));
  const location = useLocation();
  const navigate = useNavigate();
  const { session, signOut, can } = useSession();

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [accountMenu, setAccountMenu] = useState<HTMLElement | null>(null);
  const [signingOut, setSigningOut] = useState(false);

  const sections = visibleNavigation(can);

  const handleSignOut = async (): Promise<void> => {
    setAccountMenu(null);
    setSigningOut(true);

    try {
      await signOut();
    } catch {
      // The local half of the sign-out has already happened — the token is
      // gone whatever the server said. Reporting a failure here would tell the
      // user that they are still signed in, which is not true, so the only
      // sensible thing is to carry on to the sign-in form.
    } finally {
      setSigningOut(false);
      // Not `replace`: the sign-in page is where a signed-out user belongs, and
      // going "back" into a view that has no token would only bounce here again.
      void navigate('/login', { replace: true });
    }
  };

  const navigation = (
    <List component="nav" aria-label={t('nav.label')}>
      {sections.map((section, index) => (
        <Fragment key={section.labelKey ?? `section-${String(index)}`}>
          {section.labelKey !== undefined && (
            <ListSubheader component="div" disableSticky>
              {t(section.labelKey)}
            </ListSubheader>
          )}
          {section.entries.map((entry) => {
            const active = isActive(entry, location.pathname);

            return (
              <ListItemButton
                key={entry.to}
                component={RouterLink}
                to={entry.to}
                selected={active}
                aria-current={active ? 'page' : undefined}
                onClick={() => setDrawerOpen(false)}
              >
                <ListItemIcon>{entry.icon}</ListItemIcon>
                <ListItemText primary={t(entry.labelKey)} />
              </ListItemButton>
            );
          })}
        </Fragment>
      ))}
    </List>
  );

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <AppBar
        position="fixed"
        sx={{ zIndex: (current) => current.zIndex.drawer + 1 }}
      >
        <Toolbar>
          {!isWide && (
            <IconButton
              edge="start"
              color="inherit"
              aria-label={drawerOpen ? t('nav.close') : t('nav.open')}
              onClick={() => setDrawerOpen((open) => !open)}
              sx={{ mr: 1 }}
            >
              <MenuIcon />
            </IconButton>
          )}

          <Typography variant="h6" component="div" sx={{ flexGrow: 1 }}>
            {t('app.name')}
          </Typography>

          <IconButton
            color="inherit"
            onClick={(event) => setAccountMenu(event.currentTarget)}
            aria-label={t('nav.account')}
            aria-haspopup="menu"
            aria-expanded={accountMenu !== null}
          >
            <AccountCircleIcon />
          </IconButton>

          <Menu
            anchorEl={accountMenu}
            open={accountMenu !== null}
            onClose={() => setAccountMenu(null)}
          >
            {session !== null && (
              <MenuItem disabled sx={{ opacity: '1 !important' }}>
                <Typography variant="body2" color="text.secondary">
                  {session.username}
                </Typography>
              </MenuItem>
            )}
            <Divider />
            {/*
              The own password is the one administrators' view a domain master
              may open (`profile.password.write`), and the drawer's entry for
              `/admins` needs `admin.read` — so it lives here, for both roles.
            */}
            {can('profile.password.write') && (
              <MenuItem
                component={RouterLink}
                to={OWN_PASSWORD_PATH}
                onClick={() => setAccountMenu(null)}
              >
                <ListItemIcon>
                  <KeyIcon fontSize="small" />
                </ListItemIcon>
                <ListItemText>{t('nav.ownPassword')}</ListItemText>
              </MenuItem>
            )}
            <MenuItem onClick={() => void handleSignOut()} disabled={signingOut}>
              <ListItemIcon>
                <LogoutIcon fontSize="small" />
              </ListItemIcon>
              <ListItemText>
                {signingOut ? t('signOut.inProgress') : t('signOut.action')}
              </ListItemText>
            </MenuItem>
          </Menu>
        </Toolbar>
      </AppBar>

      <Drawer
        variant={isWide ? 'permanent' : 'temporary'}
        open={isWide || drawerOpen}
        onClose={() => setDrawerOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{
          width: DRAWER_WIDTH,
          flexShrink: 0,
          '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box' },
        }}
      >
        <Toolbar />
        <Box sx={{ overflow: 'auto' }}>{navigation}</Box>
      </Drawer>

      <Box component="main" id="main" sx={{ flexGrow: 1, p: 3, width: '100%' }}>
        <Toolbar />
        <Outlet />
      </Box>
    </Box>
  );
}
