import { useEffect, useState } from 'react';

import { Storefront } from '@mui/icons-material';
import LoadingButton from '@mui/lab/LoadingButton';
import {
  Alert,
  Box,
  Button,
  FormControl,
  InputLabel,
  Link,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';

import { Section } from './Section';
import type { SalesPageState } from '@/api/IGrocersListApi';
import { useSetupContext } from '@/hooks/useSetupContext';

const DEFAULT_SLUG = 'membership';
const DEFAULT_LABEL = 'Membership';

export const SalesPageSection = ({
  addToast,
}: {
  addToast: (success: boolean, message: string) => void;
}) => {
  const { api } = useSetupContext();

  const [state, setState] = useState<SalesPageState | null>(null);
  const [loading, setLoading] = useState(true);
  const [slug, setSlug] = useState(DEFAULT_SLUG);
  const [label, setLabel] = useState(DEFAULT_LABEL);
  const [menuId, setMenuId] = useState<number>(0);
  const [generating, setGenerating] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [menuBusy, setMenuBusy] = useState(false);

  useEffect(() => {
    let mounted = true;
    api
      .getSalesPageState()
      .then(next => {
        if (!mounted) return;
        setState(next);
        const initialMenuId = next.primaryMenuId || (next.menus[0]?.id ?? 0);
        setMenuId(initialMenuId);
        if (next.menuItemId > 0 && next.menuItemLabel) {
          setLabel(next.menuItemLabel);
        }
      })
      .catch(() => {
        if (!mounted) return;
        addToast(false, 'Failed to load sales page state');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [api, addToast]);

  const handleGenerate = async () => {
    setGenerating(true);
    try {
      const next = await api.createSalesPage(slug || DEFAULT_SLUG);
      setState(next);
      addToast(true, 'Sales page created');
    } catch {
      addToast(false, 'Failed to create sales page');
    } finally {
      setGenerating(false);
    }
  };

  const handleRegenerate = async () => {
    if (
      !window.confirm(
        'Regenerating will move the current sales page to the Trash and create a fresh draft. You can restore the old page from Pages → Trash if needed. Continue?'
      )
    ) {
      return;
    }
    setRegenerating(true);
    try {
      const next = await api.regenerateSalesPage(
        state?.page?.slug || slug || DEFAULT_SLUG
      );
      setState(next);
      addToast(true, 'Sales page regenerated');
    } catch {
      addToast(false, 'Failed to regenerate sales page');
    } finally {
      setRegenerating(false);
    }
  };

  const handleRemove = async () => {
    if (
      !window.confirm(
        'Remove the sales page? The page itself will be moved to the Trash (recoverable from Pages → Trash) and any nav-menu item we added will be removed. Continue?'
      )
    ) {
      return;
    }
    setRemoving(true);
    try {
      const next = await api.removeSalesPage();
      setState(next);
      addToast(true, 'Sales page removed');
    } catch {
      addToast(false, 'Failed to remove sales page');
    } finally {
      setRemoving(false);
    }
  };

  const handleAddToMenu = async () => {
    setMenuBusy(true);
    try {
      const next = await api.addSalesPageToMenu(menuId, label || DEFAULT_LABEL);
      setState(next);
      if (next.menuItemLabel) setLabel(next.menuItemLabel);
      addToast(true, 'Added to menu');
    } catch {
      addToast(false, 'Failed to add to menu');
    } finally {
      setMenuBusy(false);
    }
  };

  const handleUpdateLabel = async () => {
    setMenuBusy(true);
    try {
      const next = await api.updateSalesPageMenuItemLabel(
        label || DEFAULT_LABEL
      );
      setState(next);
      if (next.menuItemLabel) setLabel(next.menuItemLabel);
      addToast(true, 'Menu label updated');
    } catch {
      addToast(false, 'Failed to update menu label');
    } finally {
      setMenuBusy(false);
    }
  };

  const handleRemoveFromMenu = async () => {
    setMenuBusy(true);
    try {
      const next = await api.removeSalesPageFromMenu();
      setState(next);
      addToast(true, 'Removed from menu');
    } catch {
      addToast(false, 'Failed to remove from menu');
    } finally {
      setMenuBusy(false);
    }
  };

  if (loading || !state) {
    return (
      <Section title="Sales Page" icon={<Storefront color="primary" />}>
        <Typography variant="body2" color="text.secondary">
          Loading…
        </Typography>
      </Section>
    );
  }

  if (!state.supportsPattern) {
    return (
      <Section title="Sales Page" icon={<Storefront color="primary" />}>
        <Alert severity="info">
          Sales page generation requires WordPress 5.5 or newer.
        </Alert>
      </Section>
    );
  }

  return (
    <Section title="Sales Page" icon={<Storefront color="primary" />}>
      <Stack spacing={2}>
        {!state.page ? (
          <>
            <Typography variant="body2" color="text.secondary">
              Generate a membership sales page styled by your theme. After
              creation, you can edit the content like any other page.
            </Typography>
            <TextField
              label="Page slug"
              value={slug}
              onChange={e => setSlug(e.target.value)}
              helperText="Used in the URL, e.g. /membership"
              size="small"
            />
            <Box>
              <LoadingButton
                variant="contained"
                onClick={handleGenerate}
                loading={generating}
              >
                Generate sales page
              </LoadingButton>
            </Box>
          </>
        ) : (
          <>
            <Typography variant="body2">
              Your sales page:{' '}
              <Link href={state.page.viewUrl} target="_blank" rel="noreferrer">
                /{state.page.slug}
              </Link>{' '}
              <Typography
                component="span"
                variant="caption"
                color="text.secondary"
              >
                ({state.page.status})
              </Typography>
            </Typography>
            <Stack direction="row" spacing={1} flexWrap="wrap">
              <Button
                variant="contained"
                href={state.page.editUrl}
                target="_blank"
                rel="noreferrer"
              >
                Edit in WordPress
              </Button>
              <Button
                variant="outlined"
                href={state.page.previewUrl}
                target="_blank"
                rel="noreferrer"
              >
                Preview
              </Button>
              <LoadingButton
                variant="outlined"
                color="warning"
                onClick={handleRegenerate}
                loading={regenerating}
              >
                Regenerate
              </LoadingButton>
            </Stack>
            <Box>
              <LoadingButton
                variant="outlined"
                color="error"
                onClick={handleRemove}
                loading={removing}
              >
                Remove sales page
              </LoadingButton>
            </Box>

            <Box sx={{ mt: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                Add to navigation
              </Typography>

              {state.isBlockTheme ? (
                <Stack spacing={1}>
                  <Typography variant="body2" color="text.secondary">
                    Your theme uses the Site Editor for navigation. To add this
                    page to your menu, open the Site Editor, edit your header
                    template, and add a Navigation Link block pointing to{' '}
                    <code>{state.page.viewUrl}</code>.
                  </Typography>
                  <Box>
                    <Button
                      variant="outlined"
                      href={state.siteEditorUrl}
                      target="_blank"
                      rel="noreferrer"
                    >
                      Open Site Editor
                    </Button>
                  </Box>
                </Stack>
              ) : state.menus.length === 0 ? (
                <Alert severity="info">
                  No menus found —{' '}
                  <Link
                    href={state.menuEditorUrl}
                    target="_blank"
                    rel="noreferrer"
                  >
                    create one in Appearance → Menus
                  </Link>{' '}
                  first.
                </Alert>
              ) : (
                <Stack spacing={1}>
                  <FormControl size="small" sx={{ minWidth: 220 }}>
                    <InputLabel id="gl-sales-menu-select">Menu</InputLabel>
                    <Select
                      labelId="gl-sales-menu-select"
                      label="Menu"
                      value={menuId || ''}
                      onChange={e => setMenuId(Number(e.target.value))}
                      disabled={state.menuItemId > 0}
                      MenuProps={{ disableScrollLock: true }}
                    >
                      {state.menus.map(m => (
                        <MenuItem key={m.id} value={m.id}>
                          {m.name}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                  <TextField
                    label="Nav item label"
                    value={label}
                    onChange={e => setLabel(e.target.value)}
                    size="small"
                  />
                  <Box>
                    {state.menuItemId > 0 ? (
                      label !== state.menuItemLabel ? (
                        <Stack direction="row" spacing={1}>
                          <LoadingButton
                            variant="contained"
                            onClick={handleUpdateLabel}
                            loading={menuBusy}
                            disabled={!label.trim()}
                          >
                            Update label
                          </LoadingButton>
                          <LoadingButton
                            variant="outlined"
                            color="warning"
                            onClick={handleRemoveFromMenu}
                            loading={menuBusy}
                          >
                            Remove
                          </LoadingButton>
                        </Stack>
                      ) : (
                        <LoadingButton
                          variant="outlined"
                          color="warning"
                          onClick={handleRemoveFromMenu}
                          loading={menuBusy}
                        >
                          Already added — Remove
                        </LoadingButton>
                      )
                    ) : (
                      <LoadingButton
                        variant="contained"
                        onClick={handleAddToMenu}
                        loading={menuBusy}
                        disabled={!menuId}
                      >
                        Add to menu
                      </LoadingButton>
                    )}
                  </Box>
                </Stack>
              )}
            </Box>
          </>
        )}
      </Stack>
    </Section>
  );
};
