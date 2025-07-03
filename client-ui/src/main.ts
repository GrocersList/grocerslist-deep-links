async function validateApiKey(): Promise<boolean> {
  try {
    const ajaxUrl = (window as any).ajaxurl || (window as any).grocersListClient?.ajaxUrl || '/wp-admin/admin-ajax.php';

    const response = await fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'public_grocers_list_validate_api_key',
      }),
    });

    if (!response.ok) {
      return false;
    }

    const data = await response.json();

    if (data.success) {
      return data.data.is_valid;
    } else {
      return false;
    }
  } catch (error) {
    console.error('Error validating API key:', error);
    return false;
  }
}

window.addEventListener('load', async () => {
  try {
    const isValid = await validateApiKey();
    if (isValid) {
      console.log('GrocersList API key validation: Valid');
    } else {
      console.log('GrocersList API key validation: Invalid or not configured');
    }
  } catch (error) {
    console.error('GrocersList API key validation error:', error);
  }
});
