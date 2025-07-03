import { useContext } from "preact/hooks";
import {SetupContext} from "../contexts/SetupContext.tsx";

export const useSetupContext = () => useContext(SetupContext);
