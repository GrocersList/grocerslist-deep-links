import { useSetupContext } from '../hooks/useSetupContext'
import Stack from '@mui/material/Stack'
import TextField from '@mui/material/TextField'
import Switch from '@mui/material/Switch'
import FormControlLabel from '@mui/material/FormControlLabel'
import Typography from '@mui/material/Typography'
import Snackbar from '@mui/material/Snackbar'
import Alert from '@mui/material/Alert'
import Container from '@mui/material/Container'
import { useState } from 'preact/hooks'
import LoadingButton from '@mui/lab/LoadingButton'
import { Button } from '@mui/material'

interface Toast {
  id: number
  success: boolean
  message: string
  open: boolean
}

export const SettingsPage = () => {
  const {
    apiKey,
    setApiKey,
    autoRewriteEnabled,
    setAutoRewriteEnabled,
    useLinkstaLinks,
    setUseLinkstaLinks,
    api,
    clearSettings,
  } = useSetupContext()
  const [toasts, setToasts] = useState<Toast[]>([])
  const [loading, setLoading] = useState(false)
  const [apiKeyError, setApiKeyError] = useState<string | null>(null)

  const handleClose = (id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }

  const handleSave = async () => {
    if (apiKey.trim().length < 10) {
      setApiKeyError('API Key must be at least 10 characters')
      return
    }

    setApiKeyError(null)
    setLoading(true)

    const ops = [
      {
        promise: api.updateApiKey(apiKey),
        successMsg: '✅ API Key Saved!',
        errorMsg: '❌ Failed to Save API Key',
      },
      {
        promise: api.updateAutoRewrite(autoRewriteEnabled ?? false),
        successMsg: '✅ Auto Rewrite Updated!',
        errorMsg: '❌ Failed to Update Auto Rewrite',
      },
      {
        promise: api.updateUseLinkstaLinks(useLinkstaLinks ?? true),
        successMsg: '✅ Linksta Links Setting Updated!',
        errorMsg: '❌ Failed to Update Linksta Links Setting',
      },
    ]

    ops.forEach(({ promise, successMsg, errorMsg }, idx) => {
      promise
        .then(() => {
          setToasts((prev) => [
            ...prev,
            { id: Date.now() + idx, success: true, message: successMsg, open: true },
          ])
        })
        .catch(() => {
          setToasts((prev) => [
            ...prev,
            { id: Date.now() + idx, success: false, message: errorMsg, open: true },
          ])
        })
    })

    await Promise.allSettled(ops.map((op) => op.promise))
    setLoading(false)
  }

  return (
    <Container maxWidth="sm" sx={{ paddingY: 4 }}>
      <Stack spacing={2}>
        <Typography variant="h6">Grocers List Settings</Typography>

        <TextField
          label="API Key"
          variant="standard"
          value={apiKey}
          onChange={(e: { target: HTMLInputElement }) => setApiKey(e.target.value)}
          error={!!apiKeyError}
          helperText={apiKeyError}
          fullWidth
        />

        <FormControlLabel
          control={
            <Switch
              checked={autoRewriteEnabled}
              onChange={(e: { target: HTMLInputElement }) =>
                setAutoRewriteEnabled(e.target.checked)
              }
              color="primary"
            />
          }
          label="Generate Grocers List short links when saving a post"
        />

        <FormControlLabel
          control={
            <Switch
              checked={useLinkstaLinks}
              onChange={(e: { target: HTMLInputElement }) => setUseLinkstaLinks(e.target.checked)}
              color="primary"
            />
          }
          label="Serve Grocers List short links to site viewer"
        />

        {/*/!* Link Count Status Card *!/*/}
        {/*{loadingLinkCountInfo ? (*/}
        {/*  <Box sx={{display: 'flex', justifyContent: 'center', my: 2}}>*/}
        {/*    <CircularProgress />*/}
        {/*  </Box>*/}
        {/*) : linkCountInfo ? (*/}
        {/*  <LinkCountStatusCard*/}
        {/*    linkCountInfo={linkCountInfo}*/}
        {/*    runningRecount={runningRecount}*/}
        {/*    runRecount={runRecount}*/}
        {/*  />*/}
        {/*) : null}*/}

        {/*/!* Migration Status and Button *!/*/}
        {/*{linkCountInfo && linkCountInfo.isComplete && linkCountInfo.totalLinks > 0 && (*/}
        {/*  <>*/}
        {/*    {runningMigration && migrationStatus && (*/}
        {/*      <MigrationStatusCard migrationStatus={migrationStatus} />*/}
        {/*    )}*/}

        {/*    <RunMigrationButton*/}
        {/*      triggerMigration={triggerMigration}*/}
        {/*      isRunning={runningMigration}*/}
        {/*      disabled={loadingLinkCountInfo || !linkCountInfo?.isComplete || runningRecount || linkCountInfo.totalLinks === 0}*/}
        {/*    />*/}
        {/*  </>*/}
        {/*)}*/}

        <LoadingButton variant="contained" loading={loading} onClick={handleSave}>
          Save
        </LoadingButton>
        <Button variant={'outlined'} onClick={clearSettings}>
          Clear Settings
        </Button>
      </Stack>

      {toasts.map((toast, index) => (
        <Snackbar
          key={toast.id}
          open={toast.open}
          autoHideDuration={3000}
          onClose={() => handleClose(toast.id)}
          anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
          sx={{ mb: `${index * 60}px` }}
        >
          <Alert severity={toast.success ? 'success' : 'error'} sx={{ width: '100%' }}>
            {toast.message}
          </Alert>
        </Snackbar>
      ))}
    </Container>
  )
}
