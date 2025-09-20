import { Link as LinkIcon } from '@mui/icons-material';
import { Box, Button, Container, Typography } from '@mui/material';

export const AppLinksZeroState = () => {
  return (
    <Container maxWidth="lg">
      <Box
        sx={{
          display: 'flex',
          flexDirection: { xs: 'column', md: 'row' },
          alignItems: 'center',
          gap: { xs: 3, md: 4 },
          py: { xs: 2, md: 4 },
        }}
      >
        <Box
          sx={{
            flex: { xs: '1 1 100%', md: '1 1 60%' },
            display: 'flex',
            flexDirection: 'column',
            alignItems: { xs: 'center', md: 'flex-start' },
            textAlign: { xs: 'center', md: 'left' },
            maxWidth: { md: '700px' },
          }}
        >
          <Typography
            variant="body1"
            sx={{
              fontSize: { xs: '1rem', sm: '1.125rem' },
              color: 'text.primary',
              mb: 3,
              lineHeight: 1.6,
            }}
          >
            Earn more from your Amazon affiliate links. Stop driving precious
            clicks into the mobile browser, where your audience is not signed in
            to their amazon account. Convert your amazon affiliate links into
            our deep links that skip login hassles & make it easy for your
            audience to buy inside their native amazon app. Convert 5X more off
            affiliate link clicks with our app links.
          </Typography>

          <Button
            variant="contained"
            color="primary"
            size="large"
            startIcon={<LinkIcon />}
            href="https://app.grocerslist.com/creator-hq/app-links"
            component="a"
            sx={{
              textTransform: 'none',
              fontSize: { xs: '0.9rem', sm: '1rem' },
              px: { xs: 3, sm: 4 },
              py: { xs: 1, sm: 1.5 },
              borderRadius: 2,
              alignSelf: { xs: 'center', md: 'flex-start' },
            }}
          >
            Get started
          </Button>
        </Box>

        <Box
          sx={{
            flex: { xs: '1 1 100%', md: '1 1 40%' },
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'center',
            minHeight: { xs: 300, sm: 400, md: 430 },
          }}
        >
          <img
            src="https://app.grocerslist.com/AppLinksZeroStateImage.png"
            alt="App Links Feature"
          />
        </Box>
      </Box>
    </Container>
  );
};
