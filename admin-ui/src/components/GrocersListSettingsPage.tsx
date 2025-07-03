import {useContext} from "preact/hooks"
import {SetupContext} from "../contexts/SetupContext.tsx";

export const SettingsPage = () => {
  const {apiKey, setApiKey, autoRewriteEnabled, setAutoRewriteEnabled} = useContext(SetupContext)

  const save = () => {
    fetch(window.grocersList.ajaxUrl, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'grocers_list_update_key',
        _ajax_nonce: window.grocersList.nonces['grocers_list_update_api_key'],
        apiKey,
        autoRewrite: autoRewriteEnabled ? '1' : '0',
      }),
    })
  }

  return (
    <div style={{padding: 20}}>
      <h1>ZGrocers List Settings</h1>
      <p><b>Setup complete</b></p>
      <p>
        <input
          type="text"
          value={apiKey}
          onChange={e => {
            const target = e.target as HTMLInputElement;
            setApiKey(target.value);
          }}
          placeholder="API Key"
        />
      </p>
      <p>
        <label>
          <input
            type="checkbox"
            checked={autoRewriteEnabled}
            onChange={e => {
              const target = e.target as HTMLInputElement;
              setAutoRewriteEnabled(target.checked);
            }}
          />
          Auto Rewrite
        </label>
      </p>
      <button onClick={save}>Save</button>
    </div>
  )
}
