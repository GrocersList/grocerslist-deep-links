import type { ChangeEvent } from 'react';
import { useEffect, useState } from 'react';

import { Box, Checkbox, FormControlLabel, Typography } from '@mui/material';

// Add type definitions for WordPress globals
declare global {
  interface Window {
    wp: {
      data: {
        select: (store: string) => {
          getEditedPostAttribute: (attribute: string) => any;
        };
        dispatch: (store: string) => {
          editPost: (data: { meta: Record<string, any> }) => void;
        };
      };
    };
  }
}

interface PostGatingProps {
  onUpdate?: (postGated: boolean, recipeCardGated: boolean) => void;
}

export const PostGatingMetaBox = ({ onUpdate }: PostGatingProps) => {
  const [isPostGated, setIsPostGated] = useState(false);
  const [isRecipeCardGated, setIsRecipeCardGated] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Get meta values from WordPress editor
    const meta =
      window.wp.data.select('core/editor').getEditedPostAttribute('meta') || {};
    const postGated = meta.grocers_list_post_gated === '1';
    const recipeCardGated = meta.grocers_list_recipe_card_gated === '1';

    setIsPostGated(postGated);
    setIsRecipeCardGated(recipeCardGated);
    setLoading(false);
  }, []);

  const handlePostGatedChange = (event: ChangeEvent<HTMLInputElement>) => {
    const target = event.target as HTMLInputElement;
    const checked = target.checked;
    setIsPostGated(checked);

    // Update meta values in WordPress editor
    window.wp.data.dispatch('core/editor').editPost({
      meta: {
        grocers_list_post_gated: checked ? '1' : '0',
      },
    });

    // Call onUpdate if provided
    if (onUpdate) {
      onUpdate(checked, isRecipeCardGated);
    }
  };

  const handleRecipeCardGatedChange = (
    event: ChangeEvent<HTMLInputElement>
  ) => {
    const target = event.target as HTMLInputElement;
    const checked = target.checked;
    setIsRecipeCardGated(checked);

    // Update meta values in WordPress editor
    window.wp.data.dispatch('core/editor').editPost({
      meta: {
        grocers_list_recipe_card_gated: checked ? '1' : '0',
      },
    });

    // Call onUpdate if provided
    if (onUpdate) {
      onUpdate(isPostGated, checked);
    }
  };

  if (loading) {
    return <Box>Loading...</Box>;
  }

  return (
    <Box sx={{ p: 2 }}>
      <FormControlLabel
        control={
          <Checkbox
            checked={isPostGated}
            onChange={handlePostGatedChange}
            name="postGated"
          />
        }
        label="Post Gated"
      />
      <FormControlLabel
        control={
          <Checkbox
            checked={isRecipeCardGated}
            onChange={handleRecipeCardGatedChange}
            name="recipeCardGated"
          />
        }
        label="Recipe Card Gated"
      />
      <Typography
        variant="body2"
        sx={{ mt: 1, fontSize: '0.8rem', color: 'text.secondary' }}
      >
        Recipe Card Gated is a subset of Post Gated. If Post Gated is enabled,
        the entire post content will be gated. If only Recipe Card Gated is
        enabled, only recipe cards within the post will be gated.
      </Typography>
    </Box>
  );
};
