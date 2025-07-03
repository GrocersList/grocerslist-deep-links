import {useState, useRef} from 'preact/hooks'
import {useSetupContext} from '../hooks/useSetupContext'
import TextField from '@mui/material/TextField'
import Typography from '@mui/material/Typography'
import Box from '@mui/material/Box'
import Snackbar from '@mui/material/Snackbar'
import Alert from '@mui/material/Alert'
import LoadingButton from '@mui/lab/LoadingButton'
import Card from "@mui/material/Card";

export const StepEnterApiKey = ({onNext}: { onNext: () => void }) => {
  const {apiKey, setApiKey, api} = useSetupContext()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [snackbarOpen, setSnackbarOpen] = useState(false)
  const [snackbarMessage, setSnackbarMessage] = useState('')
  const [snackbarSuccess, setSnackbarSuccess] = useState(true)
  const inputRef = useRef<HTMLInputElement>(null)

  const shakeInput = () => {
    const input = inputRef.current
    if (input) {
      input.classList.remove('shake')
      void input.offsetWidth
      input.classList.add('shake')
    }
  }

  const validate = async () => {
    if (apiKey.trim().length < 10) {
      setError('API Key must be at least 10 characters')
      shakeInput()
      return
    }

    setLoading(true)
    setError(null)
    try {
      await api.updateApiKey(apiKey)
      setSnackbarMessage('✅ API Key Saved!')
      setSnackbarSuccess(true)
      setSnackbarOpen(true)
      onNext()
    } catch (err) {
      console.error('Failed to save API Key', err)
      shakeInput()
      setSnackbarMessage('❌ Failed to Save API Key')
      setSnackbarSuccess(false)
      setSnackbarOpen(true)
    } finally {
      setLoading(false)
    }
  }

  return (
    <Box mt={2}>
      <Box mb={2}>
        <Card>
          <Box padding={2}>
            <Typography>
              <p>
                💡 Grocers List offers a suite of tools built for food bloggers to drive more website
                traffic, capture more emails and drive more affiliate sales. For more information please
                visit <a href="https://grocerslist.com">grocerslist.com</a>
              </p>
              <p>
                Below we’ll walk through setup for our WP Plugin that automatically finds Amazon links on
                your blog, and converts those into deep links.
              </p>

              <p>
                Deep links open the click into the Amazon app, and are 3-5X more likely to lead to a
                purchase than opening an Amazon click into the browser. This is because the user is never
                signed into Amazon on the browser, and they are always signed in to the Amazon app, with
                1-click purchase ready to go.
              </p>
              <p>Let’s start converting your Amazon links for you.</p>
            </Typography>
          </Box>
        </Card>
      </Box>

      <Typography variant="h6" gutterBottom>
        Step 1: Enter API Key
      </Typography>
      <Typography>
        The API Key is something you’ll find inside your Grocers List account.{' '}
        <a href="https://app.grocerslist.com/creator-hq/settings">
          Click here to login and access your API key.
        </a>
      </Typography>
      <TextField
        variant="standard"
        label="API Key"
        inputRef={inputRef}
        value={apiKey}
        onChange={(e: { target: HTMLInputElement }) => setApiKey(e.target.value)}
        fullWidth
        error={Boolean(error)}
        helperText={error}
        sx={{mb: 2}}
      />

      <LoadingButton variant="contained" onClick={validate} loading={loading} fullWidth>
        Continue
      </LoadingButton>

      <Snackbar
        open={snackbarOpen}
        autoHideDuration={3000}
        onClose={() => setSnackbarOpen(false)}
        anchorOrigin={{vertical: 'bottom', horizontal: 'center'}}
      >
        <Alert severity={snackbarSuccess ? 'success' : 'error'} sx={{width: '100%'}}>
          {snackbarMessage}
        </Alert>
      </Snackbar>
    </Box>
  )
}
