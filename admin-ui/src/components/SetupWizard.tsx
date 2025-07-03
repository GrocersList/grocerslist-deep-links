import { useState } from 'preact/hooks'
import { useSetupContext } from '../hooks/useSetupContext'
import Stepper from '@mui/material/Stepper';
import Step from '@mui/material/Step';
import StepLabel from '@mui/material/StepLabel';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Container from '@mui/material/Container';
import { StepEnterApiKey } from './StepEnterApiKey'
import { StepMigratePosts } from './StepMigratePosts'

const steps = ['Enter API Key', 'Migrate Posts']

export const SetupWizard = () => {
  const { setSetupComplete, api } = useSetupContext()
  const [step, setStep] = useState(0)

  const handleMigrationComplete = async () => {
    await api.markSetupComplete()
    setSetupComplete(true)
  }

  const nextStep = () => setStep((prev) => prev + 1)

  return (
    <Container maxWidth="md" sx={{ paddingY: 4 }}>
      <Stack spacing={3}>
        <Stepper activeStep={step}>
          {steps.map((label) => (
            <Step key={label}>
              <StepLabel>{label}</StepLabel>
            </Step>
          ))}
          <Step key={"done"}>
            <StepLabel>Done</StepLabel>
          </Step>
        </Stepper>

        <Box>
          {step === 0 && <StepEnterApiKey onNext={nextStep} />}
          {step === 1 && <StepMigratePosts onComplete={handleMigrationComplete} />}
        </Box>
      </Stack>
    </Container>
  )
}
