import { AttachMoney, CheckCircle } from '@mui/icons-material';
import {
  Box,
  Button,
  Card,
  Container,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  Typography,
} from '@mui/material';

export const MembershipsZeroState = () => {
  const features = [
    'Create exclusive content for your paying members',
    'Set your own pricing and subscription tiers',
    'Build a recurring revenue stream',
    'Direct relationship with your audience',
    'Analytics and insights on member engagement',
    'Automated billing and member management',
  ];

  return (
    <Container maxWidth="lg">
      <Box
        sx={{
          py: { xs: 2, md: 4 },
        }}
      >
        <Box
          sx={{
            display: 'flex',
            flexDirection: { xs: 'column', md: 'row' },
            alignItems: 'flex-start',
            gap: { xs: 3, md: 4 },
            mb: 6,
          }}
        >
          {/* Features List - Full width on mobile, 60% on desktop */}
          <Box
            sx={{
              flex: { xs: '1 1 100%', md: '1 1 60%' },
              maxWidth: { md: '700px' },
            }}
          >
            <List sx={{ p: 0 }}>
              {features.map((feature, index) => (
                <ListItem
                  key={index}
                  sx={{
                    px: 0,
                    py: 1,
                    alignItems: 'flex-start',
                  }}
                >
                  <ListItemIcon
                    sx={{
                      minWidth: 36,
                      mt: 0.5,
                    }}
                  >
                    <CheckCircle
                      sx={{
                        color: 'success.main',
                        fontSize: 20,
                      }}
                    />
                  </ListItemIcon>
                  <ListItemText
                    primary={feature}
                    primaryTypographyProps={{
                      sx: {
                        fontSize: { xs: '0.95rem', sm: '1rem' },
                        color: 'text.secondary',
                        lineHeight: 1.6,
                      },
                    }}
                  />
                </ListItem>
              ))}
            </List>
          </Box>

          {/* Image - Full width on mobile, 40% on desktop */}
          <Box
            sx={{
              flex: { xs: '1 1 100%', md: '1 1 40%' },
              display: 'flex',
              justifyContent: 'center',
              alignItems: 'center',
              minHeight: { xs: 200, sm: 250, md: 300 },
            }}
          >
            <Card
              sx={{
                width: '100%',
                maxWidth: { xs: '280px', sm: '320px', md: '100%' },
                boxShadow: 2,
                borderRadius: 2,
                overflow: 'hidden',
              }}
            >
              <Box
                component="img"
                src="https://app.grocerslist.com/memberships-zero-state-img.png"
                alt="Membership Features"
                sx={{
                  width: '100%',
                  height: 'auto',
                  display: 'block',
                }}
              />
            </Card>
          </Box>
        </Box>
        <Typography
          variant="h6"
          component="h3"
          sx={{
            fontWeight: 600,
            mb: 2,
            fontSize: { xs: '1.1rem', sm: '1.25rem' },
          }}
        >
          Pricing
        </Typography>
        <List sx={{ p: 0 }}>
          <ListItem sx={{ px: 0, py: 0.5 }}>
            <ListItemText
              primary="• 15% transaction fee which also covers credit card fees"
              primaryTypographyProps={{
                sx: {
                  fontSize: { xs: '0.95rem', sm: '1rem' },
                  color: 'text.secondary',
                },
              }}
            />
          </ListItem>
          <ListItem sx={{ px: 0, py: 0.5 }}>
            <ListItemText
              primary="• Automatic payouts once per month"
              primaryTypographyProps={{
                sx: {
                  fontSize: { xs: '0.95rem', sm: '1rem' },
                  color: 'text.secondary',
                },
              }}
            />
          </ListItem>
        </List>
        <Button
          variant="contained"
          color="primary"
          size="large"
          startIcon={<AttachMoney />}
          href="https://app.grocerslist.com/creator-hq/memberships"
          component="a"
          sx={{
            mt: 2,
          }}
        >
          Get started
        </Button>
      </Box>
    </Container>
  );
};
