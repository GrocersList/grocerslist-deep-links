import { useState, useEffect } from 'preact/hooks'
import { useSetupContext } from '../hooks/useSetupContext'
import Stack from '@mui/material/Stack'
import Typography from '@mui/material/Typography'
import Container from '@mui/material/Container'
import Card from '@mui/material/Card'
import CardContent from '@mui/material/CardContent'
import Table from '@mui/material/Table'
import TableBody from '@mui/material/TableBody'
import TableCell from '@mui/material/TableCell'
import TableContainer from '@mui/material/TableContainer'
import TableHead from '@mui/material/TableHead'
import TableRow from '@mui/material/TableRow'
import Paper from '@mui/material/Paper'
import Button from '@mui/material/Button'
import LoadingButton from '@mui/lab/LoadingButton'
import Alert from '@mui/material/Alert'
import Chip from '@mui/material/Chip'
import Box from '@mui/material/Box'
import CircularProgress from '@mui/material/CircularProgress'
import RefreshIcon from '@mui/icons-material/Refresh'
import PlayArrowIcon from '@mui/icons-material/PlayArrow'
import RestartAltIcon from '@mui/icons-material/RestartAlt'
import type { QueueStats, UrlMapping, ProcessQueueResult, ResetFailedResult } from '../api/IGrocersListApi'

interface Toast {
  id: number
  severity: 'success' | 'error' | 'info'
  message: string
}

export const DebugMonitorPage = () => {
  const { api } = useSetupContext()
  const [queueStats, setQueueStats] = useState<QueueStats | null>(null)
  const [urlMappings, setUrlMappings] = useState<UrlMapping[]>([])
  const [loading, setLoading] = useState(true)
  const [processing, setProcessing] = useState(false)
  const [resetting, setResetting] = useState(false)
  const [toasts, setToasts] = useState<Toast[]>([])

  const addToast = (severity: 'success' | 'error' | 'info', message: string) => {
    const id = Date.now()
    setToasts(prev => [...prev, { id, severity, message }])
    setTimeout(() => {
      setToasts(prev => prev.filter(t => t.id !== id))
    }, 5000)
  }

  const loadData = async () => {
    try {
      setLoading(true)
      const [stats, mappings] = await Promise.all([
        api.getQueueStats(),
        api.getUrlMappings(50)
      ])
      setQueueStats(stats)
      setUrlMappings(mappings)
    } catch (error) {
      addToast('error', 'Failed to load debug data')
      console.error('Failed to load debug data:', error)
    } finally {
      setLoading(false)
    }
  }

  const handleProcessQueue = async () => {
    try {
      setProcessing(true)
      const result: ProcessQueueResult = await api.processQueue()
      addToast('success', `✅ Processed ${result.processed} posts. ${result.errors} errors.`)
      await loadData() // Refresh data after processing
    } catch (error) {
      addToast('error', '❌ Failed to process queue')
      console.error('Process queue error:', error)
    } finally {
      setProcessing(false)
    }
  }

  const handleResetFailedPosts = async () => {
    try {
      setResetting(true)
      const result: ResetFailedResult = await api.resetFailedPosts()
      addToast('success', `✅ Reset ${result.reset} failed posts back to pending`)
      await loadData() // Refresh data after reset
    } catch (error) {
      addToast('error', '❌ Failed to reset failed posts')
      console.error('Reset failed posts error:', error)
    } finally {
      setResetting(false)
    }
  }

  useEffect(() => {
    loadData()
  }, [])

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending': return 'warning'
      case 'processing': return 'info'
      case 'completed': return 'success'
      case 'failed': return 'error'
      default: return 'default'
    }
  }

  if (loading) {
    return (
      <Container maxWidth="lg" sx={{ paddingY: 4 }}>
        <Box display="flex" justifyContent="center" alignItems="center" minHeight="200px">
          <CircularProgress />
        </Box>
      </Container>
    )
  }

  return (
    <Container maxWidth="lg" sx={{ paddingY: 4 }}>
      <Stack spacing={3}>
        <Box display="flex" justifyContent="space-between" alignItems="center">
          <Typography variant="h4">GrocersList Debug Monitor</Typography>
          <Button
            variant="outlined"
            startIcon={<RefreshIcon />}
            onClick={loadData}
            disabled={loading}
          >
            Refresh
          </Button>
        </Box>

        <Alert severity="info">
          <Typography variant="body2">
            <strong>How it works:</strong> When you save posts with Amazon links, they're added to a queue. 
            A background worker processes them every 5 minutes to create linksta URLs and store them in the database. 
            This keeps your content rendering fast while ensuring all links get processed.
          </Typography>
        </Alert>

        {/* Queue Statistics */}
        <Card>
          <CardContent>
            <Typography variant="h6" gutterBottom>Queue Statistics</Typography>
            
            {queueStats && (
              <TableContainer component={Paper} variant="outlined">
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell><strong>Status</strong></TableCell>
                      <TableCell align="right"><strong>Count</strong></TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    <TableRow>
                      <TableCell><strong>Total Posts</strong></TableCell>
                      <TableCell align="right">{queueStats.total}</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>
                        <Chip 
                          label="Pending" 
                          color={getStatusColor('pending')} 
                          size="small" 
                        />
                      </TableCell>
                      <TableCell align="right">{queueStats.pending}</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>
                        <Chip 
                          label="Processing" 
                          color={getStatusColor('processing')} 
                          size="small" 
                        />
                      </TableCell>
                      <TableCell align="right">{queueStats.processing}</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>
                        <Chip 
                          label="Completed" 
                          color={getStatusColor('completed')} 
                          size="small" 
                        />
                      </TableCell>
                      <TableCell align="right">{queueStats.completed}</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>
                        <Chip 
                          label="Failed" 
                          color={getStatusColor('failed')} 
                          size="small" 
                        />
                      </TableCell>
                      <TableCell align="right">{queueStats.failed}</TableCell>
                    </TableRow>
                  </TableBody>
                </Table>
              </TableContainer>
            )}

            {queueStats?.nextScheduledRun && (
              <Typography variant="body2" sx={{ mt: 2 }}>
                <strong>Next scheduled run:</strong> {formatDate(queueStats.nextScheduledRun)}
              </Typography>
            )}
          </CardContent>
        </Card>

        {/* Manual Actions */}
        <Card>
          <CardContent>
            <Typography variant="h6" gutterBottom>Manual Actions</Typography>
            <Stack direction="row" spacing={2}>
              <LoadingButton
                variant="contained"
                color="primary"
                startIcon={<PlayArrowIcon />}
                loading={processing}
                onClick={handleProcessQueue}
              >
                Process Queue Now
              </LoadingButton>
              
              {queueStats && queueStats.failed > 0 && (
                <LoadingButton
                  variant="outlined"
                  color="warning"
                  startIcon={<RestartAltIcon />}
                  loading={resetting}
                  onClick={handleResetFailedPosts}
                >
                  Reset Failed Posts ({queueStats.failed})
                </LoadingButton>
              )}
            </Stack>
          </CardContent>
        </Card>

        {/* URL Mappings */}
        <Card>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Recent URL Mappings ({urlMappings.length} shown)
            </Typography>
            
            {urlMappings.length > 0 ? (
              <TableContainer component={Paper} variant="outlined" sx={{ maxHeight: 400 }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell>Original URL</TableCell>
                      <TableCell>Linksta URL</TableCell>
                      <TableCell>Hash</TableCell>
                      <TableCell>Created</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {urlMappings.map((mapping) => (
                      <TableRow key={mapping.id}>
                        <TableCell>
                          <Typography variant="body2" sx={{ 
                            maxWidth: 300, 
                            overflow: 'hidden', 
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap'
                          }}>
                            {mapping.original_url}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          <Typography variant="body2" sx={{ 
                            maxWidth: 200, 
                            overflow: 'hidden', 
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap'
                          }}>
                            {mapping.linksta_url}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          <code style={{ fontSize: '0.75rem' }}>{mapping.link_hash}</code>
                        </TableCell>
                        <TableCell>
                          <Typography variant="body2">
                            {formatDate(mapping.created_at)}
                          </Typography>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            ) : (
              <Alert severity="info">No URL mappings found</Alert>
            )}
          </CardContent>
        </Card>

        {/* Toast Messages */}
        {toasts.map((toast) => (
          <Alert 
            key={toast.id} 
            severity={toast.severity}
            sx={{ 
              position: 'fixed', 
              bottom: 16, 
              right: 16,
              zIndex: 9999,
              minWidth: 300
            }}
          >
            {toast.message}
          </Alert>
        ))}
      </Stack>
    </Container>
  )
}